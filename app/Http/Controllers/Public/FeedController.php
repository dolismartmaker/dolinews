<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Dolinews\Enums\Focus;
use App\Domain\Dolinews\Enums\Maturity;
use App\Domain\Dolinews\Feeds\FeedService;
use App\Domain\Dolinews\Feeds\RssRenderer;
use App\Domain\Dolinews\Models\Article;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Feeds (SPEC 6.4): generic RSS for every filter combination, without
 * an account and behind a bounded cache; personal tokenized feeds for
 * reader accounts. A JSON flavour serves machine consumers.
 */
class FeedController extends Controller
{
    public function __construct(
        private readonly FeedService $feeds,
        private readonly RssRenderer $rss,
    ) {}

    /**
     * Generic RSS feed with filters, cached (SPEC 6.4).
     */
    public function rss(Request $request): Response
    {
        $filters = $this->filtersFrom($request);
        $ttl = (int) config('dolinews.feeds.cache_seconds', 300);
        $key = 'feed:rss:'.md5(json_encode($filters, JSON_THROW_ON_ERROR));

        $xml = Cache::remember(
            $key,
            $ttl,
            function () use ($filters): string {
                $articles = $this->articlesFor($filters);

                return $this->rss->render($articles, [
                    'title' => 'DoliNews '.$this->feedTitle($filters),
                    'link' => $this->homeLink($filters),
                    'description' => 'Annonces de l\'écosystème Dolibarr : sorties, correctifs, sécurité.',
                    // Never the raw request: the document is cached, so
                    // the first visitor's unknown parameters - or a
                    // forged Host behind an undeclared front end - would
                    // be served to everyone for the whole TTL.
                    'self_url' => $this->selfUrl('feeds.rss', $filters),
                ]);
            },
        );

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    /**
     * Generic JSON feed with the same filters.
     */
    public function json(Request $request): JsonResponse
    {
        $filters = $this->filtersFrom($request);

        $articles = collect($this->articlesFor($filters));

        $payload = [
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 'DoliNews '.$this->feedTitle($filters),
            'home_page_url' => route('home'),
            'feed_url' => $this->selfUrl('feeds.json', $filters),
            // JSON Feed extensions are prefixed with an underscore. The
            // licence travels with the copy, as share-alike requires
            // (SPEC D15).
            '_license' => [
                'name' => (string) config('dolinews.content_license.name'),
                'url' => (string) config('dolinews.content_license.url'),
            ],
            'items' => $articles->map(static fn ($article): array => [
                'id' => route('articles.show', ['article' => $article->getKey()]),
                'url' => route('articles.show', ['article' => $article->getKey()]),
                'title' => $article->title,
                'content_text' => $article->summary,
                'date_published' => $article->published_at?->toRfc3339String(),
                'authors' => [['name' => $article->editor->name]],
                'tags' => array_values(array_filter([
                    $article->focus?->value,
                    $article->maturity->value,
                    $article->project?->slug,
                ])),
            ])->all(),
        ];

        return response()->json($payload, 200, [], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * The personal tokenized feed (SPEC 6.4): RSS readers cannot
     * authenticate, the token IS the credential, revocable by
     * regeneration from the account page.
     */
    public function personal(string $token): Response
    {
        /** @var User|null $user */
        $user = User::query()->where('feed_token', $token)->first();

        if ($user === null || ! $user->active) {
            abort(404);
        }

        $articles = $this->feeds->personalFeed(
            $user,
            (int) config('dolinews.feeds.page_size', 50),
        );

        $xml = $this->rss->render($articles, [
            'title' => 'DoliNews - flux personnel',
            'link' => route('home'),
            'description' => 'Annonces des projets et éditeurs suivis.',
            'self_url' => route('feeds.personal', ['token' => $token]),
        ]);

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    /**
     * The articles a feed serves: one version per announcement, in the
     * requested language, exactly like the sheets and the editor pages.
     *
     * The locale is handled here rather than by publicQuery's plain
     * where, which would drop what nobody translated. A feed asked in
     * French carried the French AND the English version of every
     * bilingual announcement, so a reader saw each entry twice; the
     * strict filter would have fixed that by hiding the untranslated
     * ones, which SPEC 6.1 forbids. The API keeps publicQuery's strict
     * filter: its contract is frozen (SPEC D12).
     *
     * @param  array{editor?: string|null, project?: string|null, dolibarr?: int|null, focus?: string|null, locale?: string|null, maturities?: list<string>|null, query?: array<string, mixed>}  $filters
     * @return array<int, Article>
     */
    private function articlesFor(array $filters): array
    {
        $locale = (string) ($filters['locale'] ?? app()->getLocale());

        return $this->feeds->localeSlice(
            $this->feeds->publicQuery(['locale' => null] + $filters),
            $locale,
            (int) config('dolinews.feeds.page_size', 50),
        );
    }

    /**
     * Same filters as the home page, minus pagination.
     *
     * @return array{editor?: string|null, project?: string|null, dolibarr?: int|null, focus?: string|null, locale?: string|null, maturities?: list<string>|null, query?: array<string, mixed>}
     */
    private function filtersFrom(Request $request): array
    {
        // Named maturities only, like the web surface (SPEC 6.2).
        $maturities = null;

        if ($request->filled('maturity')) {
            $valid = array_values(array_filter(
                array_map('strval', (array) $request->input('maturity')),
                static fn (string $value): bool => Maturity::tryFrom($value) !== null,
            ));
            $maturities = $valid === [] ? null : $valid;
        }

        $query = array_filter([
            'editor' => $request->input('editor'),
            'project' => $request->input('project'),
            'dolibarr' => $request->input('dolibarr'),
            'focus' => $request->input('focus'),
            'locale' => $request->input('locale'),
        ], static fn ($value): bool => $value !== null && $value !== '');

        return [
            'editor' => $query['editor'] ?? null,
            'project' => $query['project'] ?? null,
            'dolibarr' => isset($query['dolibarr']) ? (int) $query['dolibarr'] : null,
            'focus' => $query['focus'] ?? null,
            // Resolved here rather than at query time: the RSS cache key
            // is built from these filters, and a null locale would have
            // served the first visitor's language to everyone for the
            // whole TTL.
            'locale' => $query['locale'] ?? app()->getLocale(),
            'maturities' => $maturities,
            'query' => $query,
        ];
    }

    /**
     * The feed's own URL, rebuilt from the route and the filters that
     * were actually recognised.
     *
     * @param  array<string, mixed>  $filters
     */
    private function selfUrl(string $routeName, array $filters): string
    {
        $query = array_filter(
            (array) ($filters['query'] ?? []),
            static fn ($value): bool => $value !== null && $value !== '',
        );

        $maturities = $filters['maturities'] ?? null;

        if (is_array($maturities) && $maturities !== []) {
            $query['maturity'] = $maturities;
        }

        return route($routeName).($query === [] ? '' : '?'.http_build_query($query));
    }

    /**
     * Home URL carrying the active filters as query string.
     *
     * @param  array<string, mixed>  $filters
     */
    private function homeLink(array $filters): string
    {
        $query = array_filter(
            (array) ($filters['query'] ?? []),
            static fn ($value): bool => $value !== null && $value !== '',
        );

        return route('home').($query === [] ? '' : '?'.http_build_query($query));
    }

    /**
     * Human suffix describing the active filters.
     *
     * @param  array<string, mixed>  $filters
     */
    private function feedTitle(array $filters): string
    {
        $parts = [];

        if (($filters['project'] ?? null) !== null) {
            $parts[] = (string) $filters['project'];
        } elseif (($filters['editor'] ?? null) !== null) {
            $parts[] = (string) $filters['editor'];
        }

        if (($filters['focus'] ?? null) !== null) {
            $parts[] = Focus::from((string) $filters['focus'])->label();
        }

        if (($filters['dolibarr'] ?? null) !== null) {
            $parts[] = 'Dolibarr v'.$filters['dolibarr'];
        }

        return $parts === [] ? '' : '('.implode(', ', $parts).')';
    }
}

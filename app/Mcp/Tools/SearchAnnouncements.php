<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\Dolinews\Enums\Focus;
use App\Domain\Dolinews\Enums\Maturity;
use App\Domain\Dolinews\Feeds\FeedService;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Search\SearchService;
use App\Mcp\ReadingPayload;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The feed, filtered the way the site filters it (SPEC 6.1/6.7).
 *
 * Named after what it reads - announcements - and never after the
 * question D1 refuses: there is no tool asking whether a module is
 * compatible with a Dolibarr version, only one listing what was
 * announced for it.
 */
#[Name('search_announcements')]
#[Description(<<<'TEXT'
    Search the DoliNews feed: dated announcements published by editors of Dolibarr modules and services (new versions, security fixes, compatibility, end of life), each one reviewed by a moderation team before publication. Newest first.
    Every filter is optional and they combine. Without a maturity, only stable releases are returned.
    The "dolibarr" filter returns announcements whose announced range covers that major version, or that announced no range: it says what was announced, never what a module supports today. Report results as "announced on <date> for <range>", not as "compatible with".
    An empty result does not mean a module does not exist: its editor may simply not publish on DoliNews.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class SearchAnnouncements extends Tool
{
    private const PER_PAGE_DEFAULT = 10;

    private const PER_PAGE_MAX = 25;

    public function __construct(
        private readonly FeedService $feeds,
        private readonly SearchService $search,
    ) {}

    public function handle(Request $request): ResponseFactory
    {
        $input = $request->validate([
            'query' => ['nullable', 'string', 'max:100'],
            'project' => ['nullable', 'string', 'max:100'],
            'editor' => ['nullable', 'string', 'max:100'],
            'dolibarr' => ['nullable', 'integer', 'min:1', 'max:99'],
            'focus' => ['nullable', Rule::enum(Focus::class)],
            'maturity' => ['nullable', 'array', 'max:5'],
            'maturity.*' => [Rule::enum(Maturity::class)],
            'published_since' => ['nullable', 'date_format:Y-m-d'],
            'locale' => ['nullable', 'string', 'max:10'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::PER_PAGE_MAX],
        ]);

        $locale = ReadingPayload::locale($input['locale'] ?? null);

        return ReadingPayload::under($locale, function () use ($input, $locale): ResponseFactory {
            $search = isset($input['query']) ? trim((string) $input['query']) : '';

            $query = $this->feeds->publicQuery([
                'search' => $search !== '' ? $search : null,
                'project' => $input['project'] ?? null,
                'editor' => $input['editor'] ?? null,
                'dolibarr' => isset($input['dolibarr']) ? (int) $input['dolibarr'] : null,
                'focus' => $input['focus'] ?? null,
                'maturities' => isset($input['maturity'])
                    ? array_values(array_map('strval', (array) $input['maturity']))
                    : null,
                // One entry per announcement: the version in the reader's
                // language when the group has one, the source otherwise
                // (SPEC 6.1). The strict filter of the API would drop
                // every announcement nobody translated.
                'locale' => $locale,
                'locale_fallback' => true,
            ]);

            if (isset($input['published_since'])) {
                $query->where('published_at', '>=', Carbon::parse((string) $input['published_since'])->startOfDay());
            }

            $page = (int) ($input['page'] ?? 1);
            $paginator = $query->paginate(
                (int) ($input['per_page'] ?? self::PER_PAGE_DEFAULT),
                ['*'],
                'page',
                $page,
            );

            $data = [
                'announcements' => $paginator->getCollection()
                    ->map(static fn (Article $article): array => ReadingPayload::announcement($article, $locale))
                    ->values()
                    ->all(),
                'page' => $paginator->currentPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ];

            // The sheets matching the same words, as the site lists them
            // above the feed: an answer naming the module is worth more
            // than the announcement mentioning it in passing (SPEC 6.1).
            if ($search !== '' && $page === 1) {
                $data['matching_projects'] = $this->search->projects($search)
                    ->map(static fn (Project $project): array => ReadingPayload::project($project, $locale))
                    ->values()
                    ->all();
            }

            // An empty search says why it is empty (SPEC 6.1): "no
            // result" alone reads as "the module does not exist".
            if ($paginator->total() === 0) {
                $data['empty_reason'] = 'No published announcement matches these filters. DoliNews only knows what editors chose to announce here: a module may exist without being announced. Without a maturity filter only stable releases are searched.';
            }

            return Response::structured(ReadingPayload::envelope($data));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Free text, every word must appear in the title, summary or body of the announcement. Example: "factur-x", "pointeuse", "caisse".'),
            'project' => $schema->string()
                ->description('Slug of a project sheet, as returned by find_projects.'),
            'editor' => $schema->string()
                ->description('Slug of an editor, as returned by find_projects or get_editor.'),
            'dolibarr' => $schema->integer()->min(1)->max(99)
                ->description('A Dolibarr major version (for example 22): announcements whose announced range covers it, or that announced none.'),
            'focus' => $schema->string()
                ->enum(array_map(static fn (Focus $focus): string => $focus->value, Focus::cases()))
                ->description('Nature of a release: "security" for security fixes, "eol" for end of life, "compat" for compatibility work.'),
            'maturity' => $schema->array()
                ->items($schema->string()->enum(array_map(static fn (Maturity $maturity): string => $maturity->value, Maturity::cases())))
                ->description('Maturities to include. Default: stable only. Name "beta", "rc", "alpha" or "deprecated" explicitly to see them.'),
            'published_since' => $schema->string()->format('date')
                ->description('Only announcements published on or after this date (YYYY-MM-DD).'),
            'locale' => $schema->string()
                ->enum(ReadingPayload::locales())
                ->description('Language of the reader. Each announcement is returned once, in this language when a version exists, in its original language otherwise. Labels are written in this language. Default: en.'),
            'page' => $schema->integer()->min(1)->description('Page number, from 1.'),
            'per_page' => $schema->integer()->min(1)->max(self::PER_PAGE_MAX)
                ->description('Announcements per page, '.self::PER_PAGE_DEFAULT.' by default.'),
        ];
    }
}

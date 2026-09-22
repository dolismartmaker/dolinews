<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Feeds;

use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\Maturity;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Search\SearchService;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The public feed queries (SPEC 6).
 *
 * Generic feeds serve every filter combination without an account;
 * personal feeds merge the watched projects and editors of one reader
 * account, each watch applying its own filters: following a module for
 * its security fixes alone is the most frequent need (SPEC 6.4).
 */
class FeedService
{
    public function __construct(
        private readonly SearchService $search,
    ) {}

    /**
     * Public, published feed with filters (SPEC 6.1).
     *
     * @param  array{editor?: string|null, project?: string|null, dolibarr?: int|null, focus?: string|null, locale?: string|null, maturities?: array<int, string>|null, search?: string|null, locale_fallback?: bool}  $filters
     * @return Builder<Article>
     */
    public function publicQuery(array $filters): Builder
    {
        $query = Article::query()
            ->published()
            ->with(['editor', 'project']);

        // Free text first: it is the filter that empties the set, and the
        // structured ones then narrow what is left (SPEC 6.1).
        if (($filters['search'] ?? null) !== null && trim((string) $filters['search']) !== '') {
            $this->search->applyToArticles($query, (string) $filters['search']);
        }

        // Sub-select joins instead of whereHas closures: the resulting
        // builder keeps its Article generic, search and filters stay
        // unambiguous.
        if (($filters['editor'] ?? null) !== null) {
            $query->whereIn(
                'editor_id',
                Editor::query()->where('slug', (string) $filters['editor'])->select('id'),
            );
        }

        if (($filters['project'] ?? null) !== null) {
            $query->whereIn(
                'project_id',
                Project::query()->where('slug', (string) $filters['project'])->select('id'),
            );
        }

        if (($filters['dolibarr'] ?? null) !== null) {
            $major = (int) $filters['dolibarr'];
            // The version filter targets ANNOUNCEMENTS, never modules:
            // "which announcements concern v22", the consequence of D1.
            // An announcement concerns the major when it declares no
            // bound at all, or a window covering it.
            $query->where(function (Builder $inner) use ($major): Builder {
                $inner->whereNull('dolibarr_min')
                    ->orWhere(function (Builder $range) use ($major): Builder {
                        $range->where('dolibarr_min', '<=', $major)
                            ->where(fn (Builder $upper): Builder => $upper->whereNull('dolibarr_max')
                                ->orWhere('dolibarr_max', '>=', $major));

                        return $range;
                    });

                return $inner;
            });
        }

        if (($filters['focus'] ?? null) !== null) {
            $query->where('focus', (string) $filters['focus']);
        }

        if (($filters['locale'] ?? null) !== null) {
            $short = substr((string) $filters['locale'], 0, 2);

            if ($filters['locale_fallback'] ?? false) {
                // The reading surface: the version in the reader's
                // language when the group has one, the source version
                // otherwise. A strict filter emptied the feed of every
                // announcement nobody had translated, which penalises
                // the untranslated announcement in distribution - what
                // SPEC 6.1 forbids - and showed a Spanish reader an
                // empty service rather than a feed they can read in
                // another language (SPEC 15, settled 2026-09-22).
                $query->where(function (Builder $inner) use ($short): void {
                    $inner->where('locale', 'like', $short.'%')
                        ->orWhere(function (Builder $source) use ($short): void {
                            $source->where('is_source', true)
                                ->whereNotExists(function ($exists) use ($short): void {
                                    $exists->selectRaw('1')
                                        ->from('articles as sibling')
                                        ->whereColumn('sibling.translation_group_id', 'articles.translation_group_id')
                                        ->where('sibling.status', ArticleStatus::PUBLISHED->value)
                                        ->whereNull('sibling.deleted_at')
                                        ->where('sibling.locale', 'like', $short.'%');
                                });
                        });
                });
            } else {
                // The API keeps the strict filter: its contract is
                // frozen (D12), and a client asking for one locale gets
                // that locale or nothing.
                $query->where('locale', 'like', $short.'%');
            }
        }

        // Default maturity exclusion (SPEC 6.2): stable only, unless the
        // reader opted in with an explicit list.
        $maturities = $filters['maturities'] ?? null;

        if ($maturities === null || $maturities === []) {
            $query->where('maturity', Maturity::STABLE->value);
        } else {
            $query->whereIn('maturity', array_values($maturities));
        }

        return $query->orderByDesc('published_at');
    }

    /**
     * One article per translation group: the version in the reader's
     * language when the group has one, the source version otherwise.
     *
     * A sheet listed every language version of the same announcement,
     * so a bilingual editor's feed read twice. Filtering on the locale
     * alone would have dropped the announcements nobody translated,
     * which SPEC 6.1 forbids: an untranslated announcement is published
     * and distributed without penalty. Hence the fallback on the source
     * rather than a plain where on the locale.
     *
     * The window read is wider than the slice returned, since several
     * rows of the same group collapse into one.
     *
     * @param  Builder<Article>  $query  already scoped and ordered
     * @return array<int, Article>
     */
    public function localeSlice(Builder $query, string $locale, int $limit): array
    {
        return $this->onePerAnnouncement($query->limit($limit * 4)->get(), $locale)
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * One article per announcement in a collection already read: the
     * version in the given language when the group has one, the source
     * version otherwise, newest first.
     *
     * Shared by every surface that hands a reader a list of articles.
     * Distributing one announcement as ten entries is not a display
     * detail: in a feed it reads as ten announcements, and in a
     * subscription mail it becomes ten paragraphs saying the same thing,
     * which is how an address stops reading them (SPEC 6.4).
     *
     * @param  Collection<int, Article>  $articles
     * @return Collection<int, Article>
     */
    public function onePerAnnouncement(Collection $articles, string $locale): Collection
    {
        $short = substr($locale, 0, 2);

        return $articles
            ->groupBy('translation_group_id')
            ->map(static function (Collection $group) use ($short): ?Article {
                /** @var Article|null $preferred */
                $preferred = $group->first(
                    static fn (Article $article): bool => str_starts_with($article->locale, $short),
                );

                /** @var Article|null $chosen */
                $chosen = $preferred
                    ?? $group->first(static fn (Article $article): bool => $article->is_source)
                    ?? $group->first();

                return $chosen;
            })
            ->filter()
            ->sortByDesc(static fn (Article $article): int => $article->published_at?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * The personal feed of a reader account (SPEC 6.4): union of the
     * watched projects, the watched editors and - when the account asked
     * for the whole feed - everything else, each watch keeping its own
     * filters, null filters meaning the site defaults.
     *
     * $since bounds it below, for the subscription mails: only what was
     * published after that instant. A back-dated publication lands below
     * any cursor by construction and therefore mails nobody, which is
     * what keeps a catalogue of archives from becoming a mail storm.
     *
     * One entry per announcement, in the language the account reads
     * (users.locale, the only one a scheduled task can know, SPEC 6.4).
     * A reader following a project does not want its release ten times
     * because ten languages carry it.
     *
     * @return array<int, Article>
     */
    public function personalFeed(User $user, int $limit = 50, ?CarbonInterface $since = null): array
    {
        $projectWatches = $user->projectWatches()->with('project')->get();
        $editorWatches = $user->editorWatches()->with('editor')->get();

        if ($projectWatches->isEmpty() && $editorWatches->isEmpty() && ! $user->watches_all) {
            return [];
        }

        /** @var Collection<int, Article> $merged */
        $merged = collect();

        foreach ($projectWatches as $watch) {
            $project = $watch->project;

            $merged = $merged->merge($project !== null
                ? $this->watchSlice(
                    $this->publicQuery([
                        'project' => $project->slug,
                        'maturities' => $this->maturityValues($watch->maturity_filter),
                    ]),
                    $watch->focus_filter,
                    $limit,
                    $since,
                )
                : [],
            );
        }

        foreach ($editorWatches as $watch) {
            $editor = $watch->editor;

            $merged = $merged->merge($editor !== null
                ? $this->watchSlice(
                    $this->publicQuery([
                        'editor' => $editor->slug,
                        'maturities' => $this->maturityValues($watch->maturity_filter),
                    ]),
                    $watch->focus_filter,
                    $limit,
                    $since,
                )
                : [],
            );
        }

        // The whole feed, when the account asked for it: a reader who
        // has not mapped their own installation yet still wants to hear
        // about a security fix, and naming fifteen projects one by one
        // is exactly what they cannot do on day one.
        if ($user->watches_all) {
            $merged = $merged->merge($this->watchSlice(
                $this->publicQuery([
                    'maturities' => $this->maturityValues($user->watch_all_maturity_filter),
                ]),
                $user->watch_all_focus_filter,
                $limit,
                $since,
            ));
        }

        return $this->onePerAnnouncement(
            $merged->unique('id'),
            $user->locale ?? (string) config('app.locale'),
        )
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * One watch's slice, narrowed by the watch's focus filter in memory:
     * the public query stays SQL, the per-watch focus list is small.
     *
     * @param  Builder<Article>  $query
     * @param  array<int, string>|null  $focusFilter
     * @return array<int, Article>
     */
    private function watchSlice(?Builder $query, ?array $focusFilter, int $limit, ?CarbonInterface $since = null): array
    {
        if ($query === null) {
            return [];
        }

        if ($focusFilter !== null && $focusFilter !== []) {
            $query->whereIn('focus', $focusFilter);
        }

        if ($since !== null) {
            $query->where('published_at', '>', $since);
        }

        return $query->limit($limit)->get()->all();
    }

    /**
     * Maturity values of a watch filter, null meaning the site default
     * (stable only, SPEC 6.2/6.4).
     *
     * @param  array<int, string>|null  $filter
     * @return array<int, string>|null
     */
    private function maturityValues(?array $filter): ?array
    {
        if ($filter === null || $filter === []) {
            return null;
        }

        $valid = array_values(array_filter(
            $filter,
            static fn (string $value): bool => Maturity::tryFrom($value) !== null,
        ));

        return $valid === [] ? null : $valid;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Feeds;

use App\Domain\Dolinews\Enums\Focus;
use App\Domain\Dolinews\Enums\Maturity;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Models\User;
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
    /**
     * Public, published feed with filters (SPEC 6.1).
     *
     * @param  array{editor?: string|null, project?: string|null, dolibarr?: int|null, focus?: string|null, locale?: string|null, maturities?: array<int, string>|null}  $filters
     * @return Builder<Article>
     */
    public function publicQuery(array $filters): Builder
    {
        $query = Article::query()
            ->published()
            ->with(['editor', 'project']);

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
            // The language filter selects the version existing in the
            // requested language; an untranslated announcement stays
            // published and distributed without penalty (SPEC 6.1).
            $query->where('locale', 'like', substr((string) $filters['locale'], 0, 2).'%');
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
     * The personal feed of a reader account (SPEC 6.4): union of the
     * watched projects and editors, each watch keeping its own filters,
     * null filters meaning the site defaults.
     *
     * @return array<int, Article>
     */
    public function personalFeed(User $user, int $limit = 50): array
    {
        $projectWatches = $user->projectWatches()->with('project')->get();
        $editorWatches = $user->editorWatches()->with('editor')->get();

        if ($projectWatches->isEmpty() && $editorWatches->isEmpty()) {
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
                )
                : [],
            );
        }

        return $merged
            ->unique('id')
            ->sortByDesc(fn (Article $article) => $article->published_at?->getTimestamp() ?? 0)
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
    private function watchSlice(?Builder $query, ?array $focusFilter, int $limit): array
    {
        if ($query === null) {
            return [];
        }

        if ($focusFilter !== null && $focusFilter !== []) {
            $query->whereIn('focus', $focusFilter);
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

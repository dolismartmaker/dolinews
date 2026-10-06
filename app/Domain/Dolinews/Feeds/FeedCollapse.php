<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Feeds;

use App\Domain\Dolinews\Enums\Focus;
use App\Domain\Dolinews\Models\Article;
use Illuminate\Support\Collection;

/**
 * Folds a burst of announcements from one project on the feed page
 * (SPEC 6.1).
 *
 * An editor who ships four security fixes in an afternoon is doing
 * exactly what the service asks of them, and the limits now let them
 * (SPEC 5.3). The feed must not punish the reader for it: without
 * folding, one project fills the screen and every other editor is pushed
 * off the page.
 *
 * Folding is a matter of PAGE, never of record:
 *
 *   - the RSS and JSON feeds, the API and the subscription emails serve
 *     every entry. An entry already distributed that disappears is a
 *     broken contract, and an aggregator has its own way of grouping;
 *   - a security announcement never folds, neither as a follower nor
 *     over one. Folding it would hide exactly what the reader came for;
 *   - the count and the range are always stated, and what folded stays
 *     one click away. The service says what was announced: an
 *     announcement nobody can reach was not announced.
 *
 * The order is untouched (published_at descending, everywhere): only
 * announcements already adjacent in the feed are folded together, so
 * nothing is pulled out of its place in time.
 */
class FeedCollapse
{
    /**
     * Group a page of the feed.
     *
     * @param  Collection<int, Article>  $articles  ordered as displayed
     * @return list<FeedGroup>
     */
    public function group(Collection $articles): array
    {
        $threshold = $this->threshold();

        if ($threshold < 2) {
            return $this->groupNone($articles);
        }

        /** @var list<FeedGroup> $groups */
        $groups = [];

        /** @var list<Article> $run */
        $run = [];

        foreach ($articles as $article) {
            if ($run !== [] && $this->joins($run, $article)) {
                $run[] = $article;

                continue;
            }

            $groups = array_merge($groups, $this->flush($run, $threshold));
            $run = $this->foldable($article) ? [$article] : [];

            if ($run === []) {
                $groups[] = new FeedGroup($article);
            }
        }

        return array_merge($groups, $this->flush($run, $threshold));
    }

    /**
     * One group per announcement, folding nothing: the shape a screen
     * takes when it must show everything, as a search does.
     *
     * @param  Collection<int, Article>  $articles
     * @return list<FeedGroup>
     */
    public function groupNone(Collection $articles): array
    {
        return array_values(
            $articles->map(fn (Article $article): FeedGroup => new FeedGroup($article))->all()
        );
    }

    /**
     * Whether this announcement continues the run being built: same
     * project, and close enough in time to be the same burst.
     *
     * @param  list<Article>  $run
     */
    private function joins(array $run, Article $article): bool
    {
        if (! $this->foldable($article)) {
            return false;
        }

        $lead = $run[0];

        if ($lead->project_id === null || $lead->project_id !== $article->project_id) {
            return false;
        }

        $leadDate = $lead->published_at;
        $date = $article->published_at;

        if ($leadDate === null || $date === null) {
            return false;
        }

        return $date->diffInDays($leadDate) <= $this->windowDays();
    }

    /**
     * Whether an announcement may fold at all. A security announcement
     * never does: the feed exists so that it is seen.
     */
    private function foldable(Article $article): bool
    {
        return $article->focus !== Focus::SECURITY;
    }

    /**
     * Turn a finished run into groups: one folded group when it reached
     * the threshold, plain entries otherwise.
     *
     * @param  list<Article>  $run
     * @return list<FeedGroup>
     */
    private function flush(array $run, int $threshold): array
    {
        if ($run === []) {
            return [];
        }

        if (count($run) < $threshold) {
            return array_map(static fn (Article $article): FeedGroup => new FeedGroup($article), $run);
        }

        return [new FeedGroup($run[0], array_slice($run, 1))];
    }

    /**
     * Announcements of one project in a row before the oldest fold.
     */
    private function threshold(): int
    {
        return (int) config('dolinews.feeds.collapse_after', 3);
    }

    /**
     * How far apart two announcements may be and still be one burst.
     */
    private function windowDays(): int
    {
        return max(1, (int) config('dolinews.feeds.collapse_window_days', 7));
    }
}

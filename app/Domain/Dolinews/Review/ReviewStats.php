<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Review;

use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\Article;
use Illuminate\Support\Collection;

/**
 * Public queue measures (SPEC 5.1): observed delay, never promised.
 *
 * The median covers only published articles, so a clogged queue would
 * look BETTER (unprocessed articles never enter it); that is why the
 * age of the oldest pending article is published together with it and
 * never alone: the second figure has no such flaw.
 */
class ReviewStats
{
    /**
     * Median seconds between submission and publication over the last
     * published articles; null when nothing was published yet.
     */
    public function observedMedianSeconds(): ?float
    {
        $window = max(1, (int) config('dolinews.review.median_window', 20));

        $durations = Article::query()
            ->where('status', ArticleStatus::PUBLISHED->value)
            ->whereNotNull('submitted_at')
            ->whereNotNull('published_at')
            // A back-dated article was published under a date preceding
            // its submission (SPEC 5.1): its "delay" is negative and
            // measures nothing. Counting it would drag the public median
            // below zero, on the one figure the service offers as an
            // honest measure of its queue.
            ->notBackdated()
            ->orderByDesc('published_at')
            ->limit($window)
            ->get()
            ->map(fn (Article $article): float => $article->submitted_at !== null && $article->published_at !== null
                ? (float) $article->submitted_at->diffInSeconds($article->published_at)
                : 0.0)
            ->values();

        if ($durations->isEmpty()) {
            return null;
        }

        return $this->median($durations);
    }

    /**
     * Age in days of the oldest article currently pending; null when the
     * queue is empty.
     */
    public function oldestPendingAgeDays(): ?float
    {
        /** @var Article|null $oldest */
        $oldest = Article::query()
            ->where('status', ArticleStatus::PENDING->value)
            ->whereNull('deleted_at')
            ->orderBy('submitted_at')
            ->first();

        if ($oldest === null || $oldest->submitted_at === null) {
            return null;
        }

        return round((float) $oldest->submitted_at->diffInDays(now()), 1);
    }

    /**
     * Pending submissions count, the same figure the review queue shows.
     */
    public function pendingCount(): int
    {
        return Article::query()
            ->where('status', ArticleStatus::PENDING->value)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * @param  Collection<int, float>  $values
     */
    private function median(Collection $values): float
    {
        $sorted = $values->sort()->values();
        $count = $sorted->count();

        if ($count % 2 === 1) {
            return (float) $sorted[$count >> 1];
        }

        return ((float) $sorted[$count / 2 - 1] + (float) $sorted[$count / 2]) / 2.0;
    }
}

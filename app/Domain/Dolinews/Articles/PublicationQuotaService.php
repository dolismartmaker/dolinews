<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Articles;

use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The two publication limits (SPEC 5.3): a per-project token bucket
 * pacing the flow, and a per-editor queue ceiling protecting the review
 * team's time, the actually scarce resource.
 *
 * The bucket has no table: it is computed on the fly from the consuming
 * articles' timestamps. A token is RESERVED at submission and CONSUMED
 * at acceptance; a refusal returns it. Whatever the later status of a
 * published article (hidden, withdrawn), it keeps consuming its token,
 * so publish-hide-publish cannot become a bypass routine. Translations
 * never consume tokens (D14) but do occupy queue slots.
 */
class PublicationQuotaService
{
    /**
     * Whether a submission is allowed for this article, and why not.
     *
     * queue_ceiling tells the two refusals apart for the caller that has
     * to name a distinct error code (SPEC 5.2/5.3): the ceiling clears
     * as the team reviews, the bucket only with time.
     *
     * @return array{allowed: bool, reason: string, queue_ceiling: bool}
     */
    public function checkSubmission(Article $article): array
    {
        if (! $article->isTranslation()) {
            if ($this->availableTokensFor($article) < 1) {
                return [
                    'allowed' => false,
                    'reason' => 'Le crédit de publication est épuisé pour '
                        .($article->project_id !== null
                            ? 'ce projet (un jeton tous les '
                            .$this->tokenDays().' jours, plafond '
                            .$this->capacity().')'
                            : 'cet éditeur (annonces sans projet, même barème)'),
                    'queue_ceiling' => false,
                ];
            }
        }

        if ($this->pendingCount($article->editor) >= $this->queueCeiling()) {
            return [
                'allowed' => false,
                'reason' => 'Trop d\'articles de cet éditeur sont simultanément en revue (plafond '
                    .$this->queueCeiling().').',
                'queue_ceiling' => true,
            ];
        }

        return ['allowed' => true, 'reason' => '', 'queue_ceiling' => false];
    }

    /**
     * Assert the submission is allowed or throw (transactional caller).
     *
     * @throws QuotaException when either limit is reached.
     */
    public function assertSubmissionAllowed(Article $article): void
    {
        $check = $this->checkSubmission($article);

        if (! $check['allowed']) {
            throw $check['queue_ceiling']
                ? QuotaException::queueCeilingReached($check['reason'])
                : QuotaException::bucketEmpty($check['reason']);
        }
    }

    /**
     * Tokens currently available for the article's bucket scope: its
     * project, or its editor for project-less announcements which count
     * against the editor's quota at the same rate (SPEC 5.3).
     */
    public function availableTokensFor(Article $article): int
    {
        if ($article->project_id !== null) {
            /** @var Project|null $project */
            $project = Project::query()->find($article->project_id);

            if ($project === null) {
                return 0;
            }

            return $this->availableTokens(
                $this->consumingArticles($project, null),
                $project->created_at ?? now(),
            );
        }

        return $this->availableTokens(
            $this->consumingArticles(null, $article->editor),
            $article->editor->created_at ?? now(),
        );
    }

    /**
     * Timestamps at which a token was locked for the scope: reservations
     * (pending articles) and consumptions (published articles, whatever
     * their later status).
     *
     * @return Collection<int, CarbonInterface>
     */
    private function consumingArticles(?Project $projectScope, ?Editor $editorScope): Collection
    {
        $query = Article::query()
            // Rejected articles return their token: only pending,
            // published and hidden states hold or consume one. Hidden and
            // soft-deleted keep consuming (SPEC 5.3): publish-hide is not
            // a bypass.
            ->whereIn('status', [
                ArticleStatus::PENDING->value,
                ArticleStatus::PUBLISHED->value,
                ArticleStatus::HIDDEN->value,
            ])
            // A translation is the same announcement in another language:
            // it never consumes a token (SPEC 5.3, D14).
            ->where('is_source', true)
            // Neither does a back-dated publication (SPEC 5.1). Its date
            // predates the bucket's own start, so the maths below would
            // spend a token on it without ever accruing the time that
            // earns one back: a handful of them would empty a bucket
            // they never drew on. They also answer to none of what the
            // quota protects, being published by the super admin outside
            // any shared queue.
            ->notBackdated();

        if ($projectScope !== null) {
            $query->where('project_id', $projectScope->getKey());
        } elseif ($editorScope !== null) {
            // Project-less announcements draw on the editor's own bucket
            // at the same rate (SPEC 5.3).
            $query->whereNull('project_id')
                ->where('editor_id', $editorScope->getKey());
        }

        return $query->get()
            ->map(fn (Article $article): CarbonInterface => $this->consumptionTime($article));
    }

    /**
     * The timestamp a consuming article locked its token at: reservation
     * time when pending, publication time otherwise. The bucket is
     * computed on published_at whatever the later status (SPEC 5.3).
     */
    private function consumptionTime(Article $article): CarbonInterface
    {
        if ($article->status === ArticleStatus::PENDING) {
            return $article->submitted_at ?? $article->created_at ?? now();
        }

        return $article->published_at ?? $article->submitted_at ?? now();
    }

    /**
     * Token bucket math: capacity C, one token per N days, starting full
     * at the scope's creation so a launch burst stays possible. Every
     * consumption locks one token; time between events accrues new ones,
     * capped at capacity.
     *
     * @param  Collection<int, CarbonInterface>  $consumptions
     */
    private function availableTokens(Collection $consumptions, CarbonInterface $scopeStart): int
    {
        $capacity = $this->capacity();
        $periodDays = max(1, $this->tokenDays());
        $now = now();

        $remaining = (float) $capacity;
        $lastEvent = $scopeStart->copy();

        $sorted = $consumptions
            ->sortBy(fn (CarbonInterface $at): int => $at->getTimestamp())
            ->values();

        foreach ($sorted as $at) {
            if ($at->greaterThan($lastEvent)) {
                $remaining = min(
                    (float) $capacity,
                    $remaining + $lastEvent->diffInDays($at) / $periodDays,
                );
                $lastEvent = $at->copy();
            }

            $remaining -= 1.0;
        }

        if ($now->greaterThan($lastEvent)) {
            $remaining = min(
                (float) $capacity,
                $remaining + $lastEvent->diffInDays($now) / $periodDays,
            );
        }

        return max(0, (int) floor($remaining));
    }

    /**
     * Simultaneous pending articles of an editor, every project and
     * language combined: translations occupy a reviewer, they count
     * (SPEC 5.3).
     */
    public function pendingCount(Editor $editor): int
    {
        return Article::query()
            ->where('editor_id', $editor->getKey())
            ->where('status', ArticleStatus::PENDING->value)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Days to accrue one token (SPEC 15 leaves the value to observation).
     */
    private function tokenDays(): int
    {
        return max(1, (int) config('dolinews.quota.token_days', 7));
    }

    /**
     * Bucket capacity.
     */
    private function capacity(): int
    {
        return max(1, (int) config('dolinews.quota.bucket_capacity', 3));
    }

    /**
     * Per-editor queue ceiling.
     */
    private function queueCeiling(): int
    {
        return max(1, (int) config('dolinews.quota.queue_ceiling', 5));
    }
}

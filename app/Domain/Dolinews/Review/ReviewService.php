<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Review;

use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\ModerationAction;
use App\Domain\Dolinews\Enums\PublicationMode;
use App\Domain\Dolinews\Enums\ReviewDecision;
use App\Domain\Dolinews\Enums\ReviewVisibility;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\ReviewMessage;
use App\Domain\Dolinews\Moderation\ModerationService;
use App\Models\User as Account;
use App\Notifications\ReviewThreadMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The a-priori review circuit (SPEC 5.1): pull-request model, quorum of
 * three moderators, automatic publication.
 *
 * An accord is an accord on a precise text: any resubmission (explicit
 * or through an edit made while pending) moves submitted_at forward, so
 * decisions predating it stop counting. The messages stay: the effect of
 * the decisions is cancelled, not their trace.
 */
class ReviewService
{
    public function __construct(
        private readonly ModerationService $moderation,
        private readonly BootstrapPhaseService $bootstrap,
    ) {}

    /**
     * Post a message (or a decision) in the private review thread
     * (SPEC 5.5).
     *
     * A message carrying a decision is ALWAYS author-visible: the
     * internal channel deliberates, it never decides silently.
     */
    public function postMessage(
        Article $article,
        Account $from,
        string $body,
        ?ReviewDecision $decision = null,
        ?string $ruleRef = null,
        ReviewVisibility $visibility = ReviewVisibility::AUTHOR,
    ): ReviewMessage {
        $this->assertCanPost($article, $from, $decision);

        if ($decision !== null) {
            $visibility = ReviewVisibility::AUTHOR;
        }

        return DB::transaction(function () use ($article, $from, $body, $decision, $ruleRef, $visibility): ReviewMessage {
            $message = ReviewMessage::query()->create([
                'article_id' => $article->getKey(),
                'user_id' => $from->getKey(),
                'visibility' => $visibility,
                'decision' => $decision,
                'rule_ref' => $ruleRef,
                'body' => $body,
                'submission_seq' => $article->submission_seq,
            ]);

            if ($decision === ReviewDecision::ACCEPTED) {
                $this->maybePublishOnQuorum($article);
            } elseif ($decision === ReviewDecision::REJECTED) {
                $article->status = ArticleStatus::REJECTED;
                $article->save();
            }
            // changes_requested: the article stays pending, waiting for
            // the author's rework; the reminder mechanism nudges the
            // author after three idle days (SPEC 5.1).

            // Circuit emails (SPEC 5.5): every message and decision
            // reaches the author and the team, the internal-visibility
            // deliberation excepted towards the author.
            $this->notifyThread($article, $message, $from);

            return $message;
        });
    }

    /**
     * Moderators whose acceptance counts towards the quorum for this
     * article: every active moderator except the article's author, even
     * when that author is a moderator (SPEC 5.1). Conflict-of-interest
     * withdrawal is the moderator's own duty (SPEC 9.6), not a guess the
     * service makes.
     *
     * Only the messages of the CURRENT review round count: a
     * resubmission opens a new round, the previous decisions keep their
     * trace and lose their effect (SPEC 5.1).
     *
     * @return array<int, ReviewMessage>
     */
    public function currentAccords(Article $article): array
    {
        return $article->reviewMessages()
            ->where('submission_seq', $article->submission_seq)
            ->where('decision', ReviewDecision::ACCEPTED->value)
            ->where('visibility', ReviewVisibility::AUTHOR->value)
            ->where('user_id', '!=', $article->author_user_id ?? 0)
            ->get()
            ->unique('user_id')
            ->values()
            ->all();
    }

    /**
     * Publish when three distinct moderators accepted the current
     * submission (SPEC 5.1). Runs inside the caller's transaction.
     */
    private function maybePublishOnQuorum(Article $article): void
    {
        if (count($this->currentAccords($article)) < $this->quorum()) {
            return;
        }

        $article->status = ArticleStatus::PUBLISHED;
        $article->publication_mode = PublicationMode::QUORUM;
        $article->published_at = now();
        $article->save();

        Log::info('ReviewService: article published on quorum', [
            'article_id' => $article->getKey(),
        ]);
    }

    /**
     * Super-admin publication without quorum (SPEC 5.1), always logged
     * with a mandatory motive.
     *
     * Open for a third party's announcement, direct competitor included:
     * publishing a competitor serves them, the conflict of interest lies
     * in abstaining. CLOSED for the admin's own announcements and those
     * of the editor the admin belongs to, outside the bootstrap phase:
     * that is the only case where the power directly profits its holder.
     *
     * $publishedAt back-dates the publication (SPEC 5.1). It exists for
     * one purpose: carrying into the feed a version that came out before
     * the service did, so a catalogue reads as the history it is instead
     * of as a burst of same-day announcements. It is a publication
     * attribute, never a submission one -- an API token still cannot
     * choose its own date.
     *
     * The date must lie in the past. Post-dating would publish into a
     * future the feed would show as current, and would make the article
     * disappear from a feed ordered on published_at until that date came.
     *
     * @throws ReviewException when the override or the date is refused.
     */
    public function publishByAdmin(
        Article $article,
        Account $admin,
        string $motive,
        ?CarbonInterface $publishedAt = null,
    ): Article {
        if (! $admin->is_super_admin) {
            throw new ReviewException('Seul le super administrateur peut déroger au quorum.');
        }

        if ($publishedAt !== null && $publishedAt->greaterThan(now())) {
            Log::warning('ReviewService: back-dating refused, date in the future', [
                'article_id' => $article->getKey(),
                'requested' => $publishedAt->format('Y-m-d H:i:s'),
            ]);

            throw new ReviewException('Une date de publication antérieure est attendue, pas une date future.');
        }

        $ownArticle = $article->author_user_id === $admin->getKey()
            || $admin->editors()->where('editors.id', $article->editor_id)->exists();

        if ($ownArticle && ! $this->bootstrap->isOpen()) {
            Log::warning('ReviewService: admin override refused on own content outside bootstrap', [
                'article_id' => $article->getKey(),
                'admin' => $admin->getKey(),
            ]);

            throw new ReviewException(
                'La dérogation est fermée pour vos propres annonces hors phase d\'amorçage.'
            );
        }

        $mode = $this->bootstrap->isOpen()
            ? PublicationMode::BOOTSTRAP
            : PublicationMode::ADMIN_OVERRIDE;

        return DB::transaction(function () use ($article, $admin, $motive, $mode, $publishedAt): Article {
            $article->status = ArticleStatus::PUBLISHED;
            $article->publication_mode = $mode;
            $article->published_at = $publishedAt !== null
                ? Carbon::instance($publishedAt)
                : now();
            $article->save();

            // The back-dating goes into the motive rather than a column of
            // its own: the journal is what an author reads to contest an
            // act (SPEC 9.4), and a date nobody can see there is a date
            // nobody can contest.
            if ($publishedAt !== null) {
                $motive .= ' [publication antidatée au '.$publishedAt->format('Y-m-d').']';
            }

            $this->moderation->log(
                moderator: $admin,
                action: ModerationAction::PUBLISHED_BY_ADMIN,
                motive: $motive,
                article: $article,
            );

            if ($mode === PublicationMode::BOOTSTRAP) {
                // Opening a bootstrap publication counts towards the
                // ceiling; the phase may close right here.
                $this->bootstrap->recordPublication();
            }

            Log::info('ReviewService: article published by admin', [
                'article_id' => $article->getKey(),
                'mode' => $mode->value,
            ]);

            return $article;
        });
    }

    /**
     * Move an already published article back to the date it belongs to
     * (SPEC 5.1).
     *
     * publishByAdmin only back-dates at the moment of publication, which
     * leaves no way out for an archive published before back-dating
     * existed: it carries the date the review accepted it, and nothing
     * short of a raw UPDATE moved it. A raw UPDATE is exactly what this
     * method exists to avoid -- a date correction nobody can read in the
     * journal is a date correction nobody can contest (SPEC 9.4).
     *
     * The act reuses published_by_admin: the spec fixes the action set
     * (SPEC 4.5), and the correction is journalled in the motive, the
     * same place the back-dating of a fresh publication already goes.
     *
     * It does NOT count towards the bootstrap ceiling: the article was
     * already published, it was already counted, and counting it twice
     * would close the phase on publications that never happened.
     *
     * @throws ReviewException when the correction or the date is refused.
     */
    public function redatePublication(
        Article $article,
        Account $admin,
        string $motive,
        CarbonInterface $publishedAt,
    ): Article {
        if (! $admin->is_super_admin) {
            throw new ReviewException('Seul le super administrateur peut corriger une date de publication.');
        }

        if ($article->status !== ArticleStatus::PUBLISHED) {
            Log::warning('ReviewService: redating refused, article not published', [
                'article_id' => $article->getKey(),
                'status' => $article->status->value,
            ]);

            throw new ReviewException('Seul un article publié voit sa date de publication corrigée.');
        }

        if ($publishedAt->greaterThan(now())) {
            Log::warning('ReviewService: redating refused, date in the future', [
                'article_id' => $article->getKey(),
                'requested' => $publishedAt->format('Y-m-d H:i:s'),
            ]);

            throw new ReviewException('Une date de publication antérieure est attendue, pas une date future.');
        }

        // Same conflict-of-interest rule as the publication itself:
        // pulling one's own announcement to a chosen date is the same
        // power as publishing it without quorum.
        $ownArticle = $article->author_user_id === $admin->getKey()
            || $admin->editors()->where('editors.id', $article->editor_id)->exists();

        if ($ownArticle && ! $this->bootstrap->isOpen()) {
            Log::warning('ReviewService: redating refused on own content outside bootstrap', [
                'article_id' => $article->getKey(),
                'admin' => $admin->getKey(),
            ]);

            throw new ReviewException(
                'La correction de date est fermée pour vos propres annonces hors phase d\'amorçage.'
            );
        }

        return DB::transaction(function () use ($article, $admin, $motive, $publishedAt): Article {
            $before = $article->published_at?->format('Y-m-d') ?? 'inconnue';

            $article->published_at = Carbon::instance($publishedAt);
            $article->save();

            $this->moderation->log(
                moderator: $admin,
                action: ModerationAction::PUBLISHED_BY_ADMIN,
                motive: $motive.' [date de publication corrigée du '.$before
                    .' au '.$publishedAt->format('Y-m-d').']',
                article: $article,
            );

            Log::info('ReviewService: publication date corrected', [
                'article_id' => $article->getKey(),
                'from' => $before,
                'to' => $publishedAt->format('Y-m-d'),
            ]);

            return $article;
        });
    }

    /**
     * Who may post in the thread: the moderation team anywhere, the
     * author in author visibility without a decision (SPEC 5.5).
     */
    private function assertCanPost(Article $article, Account $from, ?ReviewDecision $decision): void
    {
        $isAuthor = $article->author_user_id === $from->getKey();

        if ($isAuthor && $decision === null) {
            return;
        }

        if ($from->isModerator() || $from->is_super_admin) {
            return;
        }

        Log::warning('ReviewService: unauthorized thread post refused', [
            'article_id' => $article->getKey(),
            'user_id' => $from->getKey(),
        ]);

        throw new ReviewException('Vous ne participez pas à ce fil de revue.');
    }

    /**
     * The publication quorum (SPEC 5.1, default three).
     */
    private function quorum(): int
    {
        return max(1, (int) config('dolinews.review.quorum', 3));
    }

    /**
     * Circuit notifications for one thread event (SPEC 5.5): the author
     * (unless they wrote it, and never the internal deliberation) and
     * every active moderator except the sender.
     */
    private function notifyThread(Article $article, ReviewMessage $message, Account $from): void
    {
        try {
            if ($article->author_user_id !== null && $article->author_user_id !== $from->getKey()
                && $message->visibility === ReviewVisibility::AUTHOR) {
                /** @var Account|null $author */
                $author = Account::query()->find($article->author_user_id);

                $author?->notify(new ReviewThreadMessage($article, $message));
            }

            Account::query()
                ->where('active', true)
                ->where('id', '!=', $from->getKey())
                ->where(fn ($query) => $query->where('is_moderator', true)->orWhere('is_super_admin', true))
                ->get()
                ->each(fn (Account $moderator) => $moderator->notify(new ReviewThreadMessage($article, $message)));
        } catch (\Throwable $e) {
            // A notification failure must never roll back the decision:
            // log and carry on, the thread itself is the record.
            Log::warning('ReviewService: thread notification failed', [
                'article_id' => $article->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}

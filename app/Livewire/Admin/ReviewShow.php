<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Core\Admin\Concerns\AuthorizesAdmin;
use App\Domain\Dolinews\Articles\ArticleException;
use App\Domain\Dolinews\Articles\RevisionService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\ReviewDecision;
use App\Domain\Dolinews\Enums\ReviewVisibility;
use App\Domain\Dolinews\Markdown\ArticleMarkdown;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\ArticleRevision;
use App\Domain\Dolinews\Models\ReviewMessage;
use App\Domain\Dolinews\Review\ReviewException;
use App\Domain\Dolinews\Review\ReviewService;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One reviewed article: the private thread, the decisions, the
 * post-publication revisions, and the super admin's quorum override
 * (SPEC 5.1/5.5).
 *
 * The two visibilities of the same thread (SPEC 5.5): author, read by
 * the author and the team; moderators, internal deliberation. A
 * decision is always posted author-visible.
 */
#[Layout('core.admin.layout')]
class ReviewShow extends Component
{
    use AuthorizesAdmin;

    public Article $article;

    /**
     * Message form state.
     */
    public string $messageBody = '';

    public string $messageVisibility = 'author';

    public ?string $decision = null;

    public ?string $ruleRef = null;

    /**
     * Override form state (SPEC 5.1): motive mandatory.
     */
    public string $overrideMotive = '';

    /**
     * Acknowledgement of an act that keeps the moderator on this screen.
     */
    public ?string $notice = null;

    public function mount(Article $article): void
    {
        $this->mountAuthorizeAdmin();
        $this->article = $article;
    }

    /**
     * The thread as the current moderator sees it: author-visible
     * messages plus the internal channel. The AUTHOR sees only
     * author-visible ones: that view lives in the author's workspace.
     *
     * @return array<int, array{id: int, author: string, visibility: string, decision: string|null, rule_ref: string|null, body: string, created_at: string|null}>
     */
    public function thread(): array
    {
        return $this->article->reviewMessages()
            ->with('user')
            ->get()
            ->map(static fn ($message): array => [
                'id' => $message->getKey(),
                'author' => $message->user->display_name ?? $message->user->name ?? '-',
                'visibility' => $message->visibility->value,
                'decision' => $message->decision?->value,
                'rule_ref' => $message->rule_ref,
                'body' => $message->body,
                'created_at' => $message->created_at?->format('Y-m-d H:i:s'),
            ])
            ->all();
    }

    /**
     * Accords counting towards the current quorum (SPEC 5.1).
     *
     * @return array<int, ReviewMessage>
     */
    public function accords(): array
    {
        return app(ReviewService::class)->currentAccords($this->article);
    }

    /**
     * The rendered body for review reading (SPEC D5: Markdown,
     * whitelist-rendered).
     */
    public function bodyHtml(): string
    {
        return app(ArticleMarkdown::class)->render($this->article->body);
    }

    /**
     * Pending post-publication revision, when one exists (SPEC 5.4).
     */
    public function pendingRevision(): ?ArticleRevision
    {
        return app(RevisionService::class)->pendingRevision($this->article);
    }

    /**
     * Diff of the pending revision: changed fields, before and after.
     *
     * @return list<array{field: string, before: string, after: string}>
     */
    public function revisionDiff(ArticleRevision $revision): array
    {
        $diff = [];

        foreach ($revision->payload as $field => $after) {
            $diff[] = [
                'field' => (string) $field,
                'before' => (string) ($revision->snapshot[$field] ?? ''),
                'after' => (string) $after,
            ];
        }

        return $diff;
    }

    /**
     * Post a message or a decision in the thread.
     */
    public function postMessage(ReviewService $review): void
    {
        /** @var User|null $actor */
        $actor = auth('web')->user();

        abort_unless($actor !== null, 403);

        $this->validate([
            'messageBody' => ['required', 'string', 'min:2', 'max:5000'],
            'messageVisibility' => ['required', 'in:author,moderators'],
            'decision' => ['nullable', 'in:accepted,rejected,changes_requested'],
            'ruleRef' => ['nullable', 'string', 'max:20'],
        ]);

        // A decision requires its numbered rule: no sanction and no
        // refusal without a rule that existed at the time of the facts
        // (SPEC 9.2).
        if ($this->decision !== null && $this->decision !== 'accepted') {
            $this->validate(['ruleRef' => ['required', 'string', 'max:20']]);
        }

        try {
            $review->postMessage(
                $this->article,
                $actor,
                $this->messageBody,
                $this->decision !== null ? ReviewDecision::from($this->decision) : null,
                $this->ruleRef !== '' ? $this->ruleRef : null,
                ReviewVisibility::from($this->messageVisibility),
            );
        } catch (ReviewException $e) {
            $this->addError('messageBody', $e->getMessage());

            return;
        }

        $decision = $this->decision;

        $this->reset('messageBody', 'decision', 'ruleRef');
        $this->messageVisibility = 'author';

        if ($decision === null) {
            // A plain message leaves the moderator where the discussion
            // is happening, so the acknowledgement belongs to the
            // component: a flash would only surface on the next
            // navigation, which is precisely what does not happen here.
            $this->notice = __('Message posté dans le fil de revue.');

            return;
        }

        // A decision sends the moderator back to the queue, with what
        // the act produced: staying on a page that looks unchanged is
        // what made the same accord posted twice.
        session()->flash('status', $this->decisionOutcome($review, $decision));

        $this->redirect(route('admin.review'), navigate: true);
    }

    /**
     * What the decision just posted produced, said in one sentence.
     *
     * An accord that does not complete the quorum is the ordinary case
     * and the one that used to look like nothing had happened: it says
     * how many accords the current round holds.
     */
    private function decisionOutcome(ReviewService $review, string $decision): string
    {
        if ($decision === ReviewDecision::REJECTED->value) {
            return __('Article refusé. L\'auteur est informé et peut resoumettre.');
        }

        if ($decision === ReviewDecision::CHANGES_REQUESTED->value) {
            return __('Modifications demandées à l\'auteur.');
        }

        $article = $this->article->refresh();

        if ($article->status === ArticleStatus::PUBLISHED) {
            return __('Quorum atteint : l\'article est publié dans le fil.');
        }

        return __('Accord enregistré : :count sur :quorum pour cette soumission.', [
            'count' => count($review->currentAccords($article)),
            'quorum' => max(1, (int) config('dolinews.review.quorum', 3)),
        ]);
    }

    /**
     * The super admin publishes without quorum (SPEC 5.1): always
     * journalled, motive mandatory, closed on their own content outside
     * the bootstrap phase.
     */
    public function publishOverride(ReviewService $review): void
    {
        /** @var User|null $actor */
        $actor = auth('web')->user();

        abort_unless($actor !== null && $actor->is_super_admin, 403);

        $this->validate([
            'overrideMotive' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $review->publishByAdmin($this->article, $actor, $this->overrideMotive);
        } catch (ReviewException $e) {
            $this->addError('overrideMotive', $e->getMessage());

            return;
        }

        $this->overrideMotive = '';

        session()->flash('status', __('Article publié sans quorum, dérogation journalisée avec son motif.'));

        $this->redirect(route('admin.review'), navigate: true);
    }

    /**
     * Apply a pending revision (SPEC 5.4): increments the source's
     * revision number, which perimes its translations.
     */
    public function applyRevision(RevisionService $revisions, int $revisionId): void
    {
        $revision = $this->revisionOf($revisionId);

        try {
            $revisions->apply($revision);
        } catch (ArticleException $e) {
            $this->addError('revision', $e->getMessage());
        }
    }

    /**
     * Reject a pending revision.
     */
    public function rejectRevision(RevisionService $revisions, int $revisionId): void
    {
        $revision = $this->revisionOf($revisionId);

        try {
            $revisions->reject($revision);
        } catch (ArticleException $e) {
            $this->addError('revision', $e->getMessage());
        }
    }

    /**
     * The revision, resolved through the article on screen.
     *
     * A bare findOrFail on the id would apply the revision of any other
     * article, whatever the screen shows. Trusted circle or not, an act
     * has to land on what the moderator is looking at.
     */
    private function revisionOf(int $revisionId): ArticleRevision
    {
        /** @var ArticleRevision $revision */
        $revision = $this->article->revisions()->whereKey($revisionId)->firstOrFail();

        return $revision;
    }

    public function render(): View
    {
        return view('livewire.admin.review-show', [
            'article' => $this->article,
            'thread' => $this->thread(),
            'accords' => $this->accords(),
            'bodyHtml' => $this->bodyHtml(),
            'pendingRevision' => $this->pendingRevision(),
            // Every image bound to the submission, shown as an album: a
            // forbidden picture is not something a reviewer should have to
            // find by scrolling the rendered body (SPEC 9.2, rules R3/R6).
            'media' => $this->article->media()->get(),
        ])->title($this->article->title);
    }
}

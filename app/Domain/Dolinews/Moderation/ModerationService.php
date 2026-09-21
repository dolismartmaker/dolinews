<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Moderation;

use App\Domain\Dolinews\Articles\ArticleException;
use App\Domain\Dolinews\Contributors\ContributorVerificationService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\ModerationAction;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\ContributorProof;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\ModerationLog;
use App\Domain\Dolinews\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moderation acts outside the review circuit, every one of them journalled
 * in moderation_log with the invoked numbered rule and a motive
 * (SPEC 9.4).
 *
 * Conflict-of-interest asymmetry (SPEC 9.6): withdrawal acts are NEVER
 * blocked by a conflict, whatever the targeted author; when taken in a
 * conflict situation they carry requires_confirmation and a second
 * moderator must confirm within seven days or the service cancels the
 * act automatically. Legal-obligation withdrawals stay in force, the
 * confirmation then only documents them.
 */
class ModerationService
{
    /** Days before an unconfirmed conflict act is auto-cancelled (SPEC 9.6). */
    public const CONFIRMATION_DAYS = 7;

    public function __construct(
        private readonly ContributorVerificationService $verification,
    ) {}

    /**
     * Hide a published article (SPEC 9.3). Always available, conflict or
     * not: urgency first, discussion afterwards (SPEC 9.6).
     */
    public function hideArticle(
        Article $article,
        User $moderator,
        string $motive,
        ?string $ruleRef = null,
        bool $conflictOfInterest = false,
        bool $isLegal = false,
    ): ModerationLog {
        if ($article->status !== ArticleStatus::PUBLISHED && $article->status !== ArticleStatus::HIDDEN) {
            throw new ArticleException('Seul un article publié peut être masqué.');
        }

        return DB::transaction(function () use ($article, $moderator, $motive, $ruleRef, $conflictOfInterest, $isLegal): ModerationLog {
            $article->status = ArticleStatus::HIDDEN;
            $article->save();

            return $this->log(
                moderator: $moderator,
                action: ModerationAction::HIDDEN,
                motive: $motive,
                article: $article,
                ruleRef: $ruleRef,
                requiresConfirmation: $conflictOfInterest,
                isLegal: $isLegal,
            );
        });
    }

    /**
     * Restore a hidden article.
     */
    public function unhideArticle(
        Article $article,
        User $moderator,
        string $motive,
        ?string $ruleRef = null,
    ): ModerationLog {
        return DB::transaction(function () use ($article, $moderator, $motive, $ruleRef): ModerationLog {
            $article->status = ArticleStatus::PUBLISHED;
            $article->save();

            return $this->log(
                moderator: $moderator,
                action: ModerationAction::UNHIDDEN,
                motive: $motive,
                article: $article,
                ruleRef: $ruleRef,
            );
        });
    }

    /**
     * Withdraw an article: soft delete, a trace stays for the moderation
     * team, the entry does not vanish from history (SPEC 4.3/9.3).
     */
    public function deleteArticle(
        Article $article,
        User $moderator,
        string $motive,
        ?string $ruleRef = null,
        bool $conflictOfInterest = false,
        bool $isLegal = false,
    ): ModerationLog {
        return DB::transaction(function () use ($article, $moderator, $motive, $ruleRef, $conflictOfInterest, $isLegal): ModerationLog {
            $article->deleted_at = now();
            $article->save();

            return $this->log(
                moderator: $moderator,
                action: ModerationAction::DELETED,
                motive: $motive,
                article: $article,
                ruleRef: $ruleRef,
                requiresConfirmation: $conflictOfInterest,
                isLegal: $isLegal,
            );
        });
    }

    /**
     * Restore a withdrawn article.
     */
    public function restoreArticle(
        Article $article,
        User $moderator,
        string $motive,
        ?string $ruleRef = null,
    ): ModerationLog {
        return DB::transaction(function () use ($article, $moderator, $motive, $ruleRef): ModerationLog {
            $article->deleted_at = null;
            $article->save();

            return $this->log(
                moderator: $moderator,
                action: ModerationAction::RESTORED,
                motive: $motive,
                article: $article,
                ruleRef: $ruleRef,
            );
        });
    }

    /**
     * Warn an account: the lightest sanction, logged like the rest
     * (SPEC 9.3).
     */
    public function warn(User $target, User $moderator, string $motive, ?string $ruleRef = null): ModerationLog
    {
        return $this->log(
            moderator: $moderator,
            action: ModerationAction::WARNED,
            motive: $motive,
            target: $target,
            ruleRef: $ruleRef,
        );
    }

    /**
     * Suspend an account (SPEC 9.3): a withdrawal act, never blocked by
     * a conflict of interest (SPEC 9.6).
     */
    public function suspend(
        User $target,
        User $moderator,
        string $motive,
        ?string $ruleRef = null,
        bool $conflictOfInterest = false,
        bool $isLegal = false,
    ): ModerationLog {
        return DB::transaction(function () use ($target, $moderator, $motive, $ruleRef, $conflictOfInterest, $isLegal): ModerationLog {
            $target->active = false;
            $target->save();

            return $this->log(
                moderator: $moderator,
                action: ModerationAction::SUSPENDED,
                motive: $motive,
                target: $target,
                ruleRef: $ruleRef,
                requiresConfirmation: $conflictOfInterest,
                isLegal: $isLegal,
            );
        });
    }

    /**
     * Revoke a contribution proof (SPEC 9.1): the proof row survives
     * with revoked_at set, keeping the address bound (SPEC 3.4/9.8).
     */
    public function revokeProof(
        ContributorProof $proof,
        User $moderator,
        string $motive,
        ?string $ruleRef = null,
        bool $conflictOfInterest = false,
    ): ModerationLog {
        return DB::transaction(function () use ($proof, $moderator, $motive, $ruleRef, $conflictOfInterest): ModerationLog {
            $this->verification->revoke($proof);

            /** @var User|null $owner */
            $owner = $proof->user()->first();

            return $this->log(
                moderator: $moderator,
                action: ModerationAction::PROOF_REVOKED,
                motive: $motive,
                target: $owner,
                ruleRef: $ruleRef,
                requiresConfirmation: $conflictOfInterest,
            );
        });
    }

    /**
     * Log a claim over a project sheet (SPEC 9.5): the (type,
     * external_id) unique constraint detects conflicts, humans resolve
     * them; the claim opens the human circuit.
     */
    public function logClaim(Project $project, User $claimant, string $motive): ModerationLog
    {
        return $this->log(
            moderator: $claimant,
            action: ModerationAction::CLAIMED,
            motive: $motive,
            project: $project,
        );
    }

    /**
     * Transfer a project sheet to another editor (SPEC 9.5): the
     * heaviest act, resolved by the review circuit, never a unilateral
     * advantage (SPEC 9.6).
     */
    public function transferProject(
        Project $project,
        Editor $toEditor,
        User $moderator,
        string $motive,
        ?string $ruleRef = null,
    ): ModerationLog {
        return DB::transaction(function () use ($project, $toEditor, $moderator, $motive, $ruleRef): ModerationLog {
            $project->editor_id = $toEditor->getKey();
            $project->save();

            return $this->log(
                moderator: $moderator,
                action: ModerationAction::TRANSFERRED,
                motive: $motive,
                project: $project,
                editor: $toEditor,
                ruleRef: $ruleRef,
            );
        });
    }

    /**
     * Confirm a conflict-of-interest act: a second moderator takes
     * responsibility within seven days (SPEC 9.6). The confirmer must
     * not be the actor.
     */
    public function confirm(ModerationLog $log, User $confirmer): ModerationLog
    {
        if (! $log->requires_confirmation) {
            throw new ModerationException('Cet acte ne requiert pas de confirmation.');
        }

        if ($log->confirmed_at !== null) {
            throw new ModerationException('Cet acte est déjà confirmé.');
        }

        if ($log->moderator_user_id === $confirmer->getKey()) {
            throw new ModerationException('Le second modérateur ne peut pas être l\'auteur de l\'acte.');
        }

        $log->confirmed_by_user_id = $confirmer->getKey();
        $log->confirmed_at = now();
        $log->save();

        return $log;
    }

    /**
     * Auto-cancel every conflict act left unconfirmed beyond seven days
     * (SPEC 9.6): the effect is lifted, the cancellation is journalled
     * without moderator_user_id - written by the service, told apart
     * from a human decision. Legal withdrawals stay in force: there the
     * confirmation only documents.
     *
     * @return int number of acts cancelled this run
     */
    public function expireUnconfirmed(): int
    {
        $deadline = now()->subDays(self::CONFIRMATION_DAYS);

        $expired = ModerationLog::query()
            ->where('requires_confirmation', true)
            ->whereNull('confirmed_at')
            ->where('is_legal', false)
            ->where('created_at', '<', $deadline)
            ->get();

        foreach ($expired as $log) {
            DB::transaction(function () use ($log): void {
                $this->applyReversal($log);

                ModerationLog::query()->create([
                    'moderator_user_id' => null,
                    'action' => ($log->action->reversal() ?? $log->action)->value,
                    'article_id' => $log->article_id,
                    'project_id' => $log->project_id,
                    'editor_id' => $log->editor_id,
                    'user_id' => $log->user_id,
                    'rule_ref' => $log->rule_ref,
                    'motive' => 'Annulation automatique : confirmation non reçue sous '
                        .self::CONFIRMATION_DAYS.' jours.',
                    'requires_confirmation' => false,
                    'is_legal' => false,
                ]);

                Log::info('ModerationService: unconfirmed act auto-cancelled', [
                    'original_log' => $log->getKey(),
                    'action' => $log->action->value,
                ]);
            });
        }

        return $expired->count();
    }

    /**
     * Lift the effect of an expired act: restore the article, the
     * account or nothing (proof revocation has no reversal: re-granting
     * is a fresh proof, SPEC 9.6 commentary).
     */
    private function applyReversal(ModerationLog $log): void
    {
        if ($log->article_id !== null && $log->article !== null) {
            $article = $log->article;

            if ($log->action === ModerationAction::HIDDEN) {
                $article->status = ArticleStatus::PUBLISHED;
                $article->save();
            } elseif ($log->action === ModerationAction::DELETED) {
                $article->deleted_at = null;
                $article->save();
            }
        }

        if ($log->user_id !== null && $log->action === ModerationAction::SUSPENDED) {
            /** @var User|null $target */
            $target = User::query()->find($log->user_id);

            if ($target !== null) {
                $target->active = true;
                $target->save();
            }
        }
    }

    /**
     * Write one moderation_log row. Every act names the invoked numbered
     * rule and a motive; without journal nor appeal, moderation drifts
     * towards arbitrariness (SPEC 9.4).
     */
    public function log(
        User $moderator,
        ModerationAction $action,
        string $motive,
        ?Article $article = null,
        ?Project $project = null,
        ?Editor $editor = null,
        ?User $target = null,
        ?string $ruleRef = null,
        bool $requiresConfirmation = false,
        bool $isLegal = false,
    ): ModerationLog {
        if (trim($motive) === '') {
            throw new ModerationException('Un motif est obligatoire pour tout acte de modération.');
        }

        return ModerationLog::query()->create([
            'moderator_user_id' => $moderator->getKey(),
            'action' => $action,
            'article_id' => $article?->getKey(),
            'project_id' => $project?->getKey(),
            'editor_id' => $editor?->getKey(),
            'user_id' => $target?->getKey(),
            'rule_ref' => $ruleRef,
            'motive' => $motive,
            'requires_confirmation' => $requiresConfirmation,
            'is_legal' => $isLegal,
        ]);
    }
}

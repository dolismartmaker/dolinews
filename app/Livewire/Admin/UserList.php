<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Core\Audit\AuditLogger;
use App\Domain\Dolinews\Contributors\ContributorVerificationException;
use App\Domain\Dolinews\Contributors\ContributorVerificationService;
use App\Domain\Dolinews\Models\ContributorProof;
use App\Domain\Dolinews\Moderation\ModerationService;
use App\Models\User;
use Caprel\Admin\Impersonation\Impersonator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Account list (thin, S15): columns and query only, plus the super
 * admin's impersonation entry point. Suspensions and proof revocations
 * are moderation acts: they live in the moderation log and are acted on
 * from here with a motive and a rule reference.
 *
 * The passage to the contributor class is acted on from here too, by the
 * manual validation of SPEC 3.3: editors with no public repository have
 * no git history to be found in, and anonymised forge addresses can
 * receive no one-time code.
 *
 * @extends AdminList<User>
 */
class UserList extends AdminList
{
    /**
     * Moderation act form state (SPEC 9.3/9.4): motive and rule are
     * mandatory, conflict-of-interest and legal flags drive the
     * confirmation circuit (SPEC 9.6).
     */
    public ?int $actUserId = null;

    public string $actMotive = '';

    public string $actRule = '';

    public bool $actConflict = false;

    public bool $actLegal = false;

    /**
     * Contribution proof form state: the commit address the manual
     * validation binds (SPEC 3.3/3.4), and the motive and rule a
     * revocation is journalled with.
     */
    public string $proofAddress = '';

    public string $proofMotive = '';

    public string $proofRule = '';

    public bool $proofConflict = false;

    public function mount(): void
    {
        $this->mountAuthorizeAdmin();
    }

    /**
     * @return Builder<User>
     */
    protected function baseQuery(): Builder
    {
        // Being a contributor is not a column but the existence of an
        // active proof (SPEC 3.1): a sub-select keeps the screen to one
        // query where a per-row isContributor() would add fifteen.
        return User::query()
            ->select('users.*')
            ->withExists([
                'proofs as contributor' => fn (Builder $proofs): Builder => $proofs->whereNull('revoked_at'),
            ]);
    }

    public function heading(): string
    {
        return __('Comptes');
    }

    public function intro(): string
    {
        return __('Les deux classes de comptes : lecteur, qui ne peut qu\'être abonné, et contributeur, admis après preuve de contribution.');
    }

    /**
     * @return list<array{key: string, label: string, sortable: bool, searchable: bool}>
     */
    protected function columns(): array
    {
        return [
            ['key' => 'id', 'label' => __('Id'), 'sortable' => true, 'searchable' => false],
            ['key' => 'email', 'label' => __('Adresse électronique'), 'sortable' => true, 'searchable' => true],
            ['key' => 'name', 'label' => __('Nom'), 'sortable' => true, 'searchable' => true],
            ['key' => 'contributor', 'label' => __('Contributeur'), 'sortable' => false, 'searchable' => false],
            ['key' => 'is_moderator', 'label' => __('Modérateur'), 'sortable' => true, 'searchable' => false],
            ['key' => 'is_super_admin', 'label' => __('Super admin'), 'sortable' => true, 'searchable' => false],
            ['key' => 'active', 'label' => __('Actif'), 'sortable' => true, 'searchable' => false],
            ['key' => 'email_verified_at', 'label' => __('Adresse vérifiée le'), 'sortable' => true, 'searchable' => false],
        ];
    }

    /**
     * Render the boolean columns as words: the raw cast prints "1" and
     * an empty cell, which reads as missing data rather than as "no".
     */
    public function formatCell(Model $row, string $key): string
    {
        if (in_array($key, ['contributor', 'is_moderator', 'is_super_admin', 'active'], true)) {
            return $row->getAttribute($key) ? __('oui') : __('non');
        }

        return parent::formatCell($row, $key);
    }

    public function actions(): array
    {
        $actions = [];

        $user = auth('web')->user();

        if ($user instanceof User && $user->is_super_admin) {
            $actions[] = ['label' => __('Gérer'), 'method' => 'openAct'];
            $actions[] = ['label' => __('Basculer vers'), 'method' => 'switchTo', 'class' => 'btn-secondary'];
        }

        return $actions;
    }

    public function panelView(): ?string
    {
        return 'livewire.admin.partials.user-act';
    }

    /**
     * The account the open act targets, for the panel to name it. Null when
     * no act is open.
     */
    public function actTarget(): ?User
    {
        if ($this->actUserId === null) {
            return null;
        }

        return User::query()->find($this->actUserId);
    }

    /**
     * Active proofs of the open account, the only ones a revocation can
     * target: a revoked proof stays in base with revoked_at set, keeping
     * the address bound (SPEC 3.4/9.8).
     *
     * @return Collection<int, ContributorProof>
     */
    public function targetProofs(): Collection
    {
        if ($this->actUserId === null) {
            /** @var Collection<int, ContributorProof> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var Collection<int, ContributorProof> $proofs */
        $proofs = ContributorProof::query()
            ->where('user_id', $this->actUserId)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get();

        return $proofs;
    }

    /**
     * Open the moderation act form for one account.
     */
    public function openAct(int $userId): void
    {
        $this->actUserId = $userId;
        $this->actMotive = '';
        $this->actRule = '';
        $this->actConflict = false;
        $this->actLegal = false;

        // The account address is the sane default for a manual
        // validation: it is the one the operator was given, and binding
        // it blocks a second account on the same identity (SPEC 3.4).
        // Still editable, a commit address often differing from it.
        $this->proofAddress = (string) User::query()->findOrFail($userId)->email;
        $this->proofMotive = '';
        $this->proofRule = '';
        $this->proofConflict = false;
        $this->resetErrorBag();
    }

    /**
     * Close the act panel without acting.
     */
    public function closeAct(): void
    {
        $this->actUserId = null;
        $this->resetErrorBag();
    }

    /**
     * Suspend the account: a withdrawal act, never blocked by a
     * conflict of interest, confirmed within seven days when taken in
     * one (SPEC 9.6).
     */
    public function suspend(): void
    {
        $actor = $this->requireSuperAdmin();

        $target = User::query()->findOrFail($this->actUserId);

        $this->validate([
            'actMotive' => ['required', 'string', 'min:10', 'max:2000'],
            'actRule' => ['required', 'string', 'max:20'],
        ]);

        app(ModerationService::class)->suspend(
            $target,
            $actor,
            $this->actMotive,
            $this->actRule,
            $this->actConflict,
            $this->actLegal,
        );

        $this->actUserId = null;
        $this->success(__('Compte suspendu, acte journalisé.'));
    }

    /**
     * Restore a suspended account.
     */
    public function unsuspend(int $userId): void
    {
        $this->requireSuperAdmin();

        $target = User::query()->findOrFail($userId);

        if (! $target->active) {
            $target->active = true;
            $target->save();

            // The suspension sits in the moderation log; lifting it has
            // to leave a trace too, or the log reads as still in force
            // (SPEC 9.4).
            app(AuditLogger::class)->log('account.unsuspended', $target);
        }

        $this->actUserId = null;
        $this->success(__('Compte rétabli.'));
    }

    /**
     * Toggle the moderator flag (team composition, SPEC 9.1). Reaching
     * the floor of six closes the bootstrap phase for good (SPEC 5.1).
     */
    public function toggleModerator(int $userId): void
    {
        $this->requireSuperAdmin();

        $target = User::query()->findOrFail($userId);
        $target->is_moderator = ! $target->is_moderator;
        $target->save();

        // Composition of the moderation team (SPEC 9.1): who joined it,
        // when, and on whose decision.
        app(AuditLogger::class)->log(
            $target->is_moderator ? 'moderator.added' : 'moderator.removed',
            $target,
        );

        $this->success($target->is_moderator
            ? __('Compte ajouté à l\'équipe de modération.')
            : __('Compte retiré de l\'équipe de modération.'));
    }

    /**
     * Qualify the account as a contributor by manual validation (SPEC
     * 3.3): the way out for an editor with no public repository, and for
     * an anonymised forge address no code can be sent to.
     *
     * The address is hashed and bound like any other proof, so the one
     * account per identity rule holds here too (SPEC 3.4).
     */
    public function grantContributor(): void
    {
        $this->requireSuperAdmin();

        $target = User::query()->findOrFail($this->actUserId);

        $this->validate([
            'proofAddress' => ['required', 'email', 'max:255'],
            'proofMotive' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $proof = app(ContributorVerificationService::class)
                ->grantManual($target, $this->proofAddress);
        } catch (ContributorVerificationException $e) {
            $this->addError('proofAddress', $e->getMessage());

            return;
        }

        // Not a moderation_log entry: the action enum of SPEC 4.5 is
        // closed and lists withdrawals, where this one grants a right.
        // The transverse audit carries it, as it carries the moderator
        // flag and the lifting of a suspension.
        app(AuditLogger::class)->log('contributor.granted_manually', $target, [
            'proof_id' => $proof->getKey(),
            'motive' => $this->proofMotive,
        ]);

        $this->proofMotive = '';
        $this->success(__('Compte qualifié en contributeur, acte journalisé.'));
    }

    /**
     * Revoke one proof of the open account (SPEC 9.1): a withdrawal act,
     * so it goes through whatever the conflict of interest and is
     * confirmed within seven days when taken in one (SPEC 9.6).
     */
    public function revokeProof(int $proofId): void
    {
        $actor = $this->requireSuperAdmin();

        $this->validate([
            'proofMotive' => ['required', 'string', 'min:10', 'max:2000'],
            'proofRule' => ['required', 'string', 'max:20'],
        ]);

        // Scoped on the open account: the motive just typed names that
        // account, so the act must not be able to land on another.
        $proof = ContributorProof::query()
            ->where('user_id', $this->actUserId)
            ->findOrFail($proofId);

        app(ModerationService::class)->revokeProof(
            $proof,
            $actor,
            $this->proofMotive,
            $this->proofRule,
            $this->proofConflict,
        );

        $this->proofMotive = '';
        $this->proofRule = '';
        $this->proofConflict = false;
        $this->success(__('Preuve révoquée, acte journalisé.'));
    }

    /**
     * Starts an impersonation through the package, which checks
     * canImpersonate() / canBeImpersonated(), logs and traces it.
     *
     * Lands on the account page of the target rather than on the
     * back-office: what the operator came to see is what that account sees.
     */
    public function switchTo(int $userId): void
    {
        $actor = $this->requireSuperAdmin();
        $target = User::query()->findOrFail($userId);

        app(Impersonator::class)->start($actor, $target);

        $this->redirect(route('account.show'));
    }

    /**
     * The acting super admin, or a 403.
     *
     * Every act of this screen is reserved to the operator: the
     * moderation team reaches the back-office (SPEC 9.1) but neither
     * suspends an account nor qualifies one.
     */
    private function requireSuperAdmin(): User
    {
        /** @var User|null $actor */
        $actor = auth('web')->user();

        abort_unless($actor instanceof User && $actor->is_super_admin, 403);

        return $actor;
    }
}

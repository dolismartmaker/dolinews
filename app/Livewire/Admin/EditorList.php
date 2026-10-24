<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Core\Audit\AuditLogger;
use App\Domain\Dolinews\Articles\PublicationQuotaService;
use App\Domain\Dolinews\Editors\EditorException;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Enums\EditorRole;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Translation\TranslationRouter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Editor directory and the operator's acts on it (SPEC 3.3/9.1): manual
 * validation of an editor without a public repository, which sets
 * verified_at, plus the sheet and its membership.
 *
 * Membership is normally the owner's business, from its own account page.
 * The operator needs it too: an owner who has left, an editor created by
 * mistake, a team to repair. Hence the same acts here, reserved to the
 * super admin and journalled, where the owner's own path stays unchanged.
 *
 * @extends AdminList<Editor>
 */
class EditorList extends AdminList
{
    /**
     * Sheet form state. $editorId names the open editor, $creating tells
     * the panel it is building a new one instead.
     */
    public ?int $editorId = null;

    public bool $creating = false;

    public string $editorName = '';

    public string $editorDescription = '';

    public string $editorWebsite = '';

    public string $editorContactEmail = '';

    public string $editorOwnerEmail = '';

    public string $editorMemberEmail = '';

    /**
     * Monthly translation ceiling of the open editor, as typed. Blank
     * means the deployment default (SPEC 5.7).
     */
    public string $editorTranslationCeiling = '';

    /**
     * Raised publication quota of the open editor, as typed (SPEC 5.3):
     * bucket capacity, queue ceiling, end date and motive. A blank value
     * keeps the deployment default.
     */
    public string $editorQuotaCapacity = '';

    public string $editorQuotaCeiling = '';

    public string $editorQuotaUntil = '';

    public string $editorQuotaMotive = '';

    public function mount(): void
    {
        $this->mountAuthorizeAdmin();
    }

    /**
     * @return Builder<Editor>
     */
    protected function baseQuery(): Builder
    {
        return Editor::query()->select('editors.*');
    }

    public function heading(): string
    {
        return __('Éditeurs');
    }

    public function intro(): string
    {
        return __('L\'organisation ou la personne qui publie. La validation manuelle est la voie de sortie d\'un éditeur sans dépôt public.');
    }

    /**
     * @return list<array{key: string, label: string, sortable: bool, searchable: bool}>
     */
    protected function columns(): array
    {
        return [
            ['key' => 'id', 'label' => __('Id'), 'sortable' => true, 'searchable' => false],
            ['key' => 'slug', 'label' => __('Slug'), 'sortable' => true, 'searchable' => true],
            ['key' => 'name', 'label' => __('Nom'), 'sortable' => true, 'searchable' => true],
            ['key' => 'contact_email', 'label' => __('Contact'), 'sortable' => false, 'searchable' => true],
            ['key' => 'website', 'label' => __('Site'), 'sortable' => false, 'searchable' => true],
            ['key' => 'verified_at', 'label' => __('Validé le'), 'sortable' => true, 'searchable' => false],
        ];
    }

    /**
     * The action calls validateEditor() and not validate(): the latter is
     * Livewire's own validation method, which the action dispatcher refuses to
     * call because the component does not declare it -- the button raised a
     * MethodNotFoundException instead of validating anything.
     */
    public function actions(): array
    {
        $actions = [];

        if ($this->canManage()) {
            $actions[] = ['label' => __('Gérer'), 'method' => 'openEditor'];
        }

        $actions[] = ['label' => __('Valider'), 'method' => 'validateEditor'];

        return $actions;
    }

    /**
     * Whether the viewer may act on the sheets. The panel asks it for its
     * creation entry point, actions() for its management button: a
     * moderator sees the directory and validates, nothing more.
     */
    public function canManage(): bool
    {
        $user = auth('web')->user();

        return $user instanceof User && $user->is_super_admin;
    }

    public function panelView(): ?string
    {
        return 'livewire.admin.partials.editor-act';
    }

    /**
     * The open editor, for the panel to name it. Null while none is open
     * or a creation is in progress.
     */
    public function editorTarget(): ?Editor
    {
        if ($this->editorId === null) {
            return null;
        }

        return Editor::query()->find($this->editorId);
    }

    /**
     * Accounts attached to the open editor, with their pivot role.
     *
     * @return Collection<int, User>
     */
    public function editorMembers(): Collection
    {
        $editor = $this->editorTarget();

        if ($editor === null) {
            /** @var Collection<int, User> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var Collection<int, User> $members */
        $members = $editor->users()->orderBy('users.id')->get();

        return $members;
    }

    /**
     * Open an editor for management, its sheet loaded in the form.
     */
    public function openEditor(int $editorId): void
    {
        $this->requireSuperAdmin();

        $editor = Editor::query()->findOrFail($editorId);

        $this->editorId = $editor->getKey();
        $this->creating = false;
        $this->editorName = $editor->name;
        $this->editorDescription = (string) $editor->description;
        $this->editorWebsite = (string) $editor->website;
        $this->editorContactEmail = $editor->contact_email;
        $this->editorOwnerEmail = '';
        $this->editorMemberEmail = '';
        $this->editorTranslationCeiling = $editor->translation_monthly_characters === null
            ? ''
            : (string) $editor->translation_monthly_characters;
        $this->loadQuotaRaise($editor);
        $this->resetErrorBag();
    }

    /**
     * Open the creation form: a blank sheet and the owner to designate.
     */
    public function openCreate(): void
    {
        $this->requireSuperAdmin();

        $this->editorId = null;
        $this->creating = true;
        $this->editorName = '';
        $this->editorDescription = '';
        $this->editorWebsite = '';
        $this->editorContactEmail = '';
        $this->editorOwnerEmail = '';
        $this->editorMemberEmail = '';
        $this->editorTranslationCeiling = '';
        $this->editorQuotaCapacity = '';
        $this->editorQuotaCeiling = '';
        $this->editorQuotaUntil = '';
        $this->editorQuotaMotive = '';
        $this->resetErrorBag();
    }

    /**
     * Close the panel without acting.
     */
    public function closeEditor(): void
    {
        $this->editorId = null;
        $this->creating = false;
        $this->resetErrorBag();
    }

    /**
     * Create the editor being built, or save the open sheet.
     *
     * The slug never follows a rename: it addresses the public page
     * (/editeurs/{slug}) and the feeds, which outside links point at.
     */
    public function saveEditor(): void
    {
        $this->requireSuperAdmin();

        $this->validate([
            'editorName' => ['required', 'string', 'max:150'],
            'editorDescription' => ['nullable', 'string', 'max:2000'],
            'editorWebsite' => ['nullable', 'url:http,https', 'max:255'],
            'editorContactEmail' => ['required', 'email', 'max:255'],
        ]);

        if ($this->creating) {
            $this->createEditor();

            return;
        }

        $editor = Editor::query()->findOrFail($this->editorId);

        $editor->name = $this->editorName;
        $editor->description = $this->nullIfBlank($this->editorDescription);
        $editor->website = $this->nullIfBlank($this->editorWebsite);
        $editor->contact_email = $this->editorContactEmail;
        $editor->save();

        app(AuditLogger::class)->log('editor.updated', $editor, [
            'editor_slug' => $editor->slug,
        ]);

        $this->success(__('Fiche éditeur enregistrée.'));
    }

    /**
     * Shared translation route of the open editor this month: what it
     * spent, the ceiling that applies, and the deployment default the
     * ceiling falls back to. Null while no editor is open.
     *
     * @return array{spent: int, ceiling: int, default: int, own: bool, own_key: bool}|null
     */
    public function translationAllowance(): ?array
    {
        $editor = $this->editorTarget();

        if ($editor === null) {
            return null;
        }

        $router = app(TranslationRouter::class);

        return [
            'spent' => $router->spent($editor),
            'ceiling' => $router->ceiling($editor),
            'default' => $router->defaultCeiling(),
            'own' => $editor->translation_monthly_characters !== null,
            'own_key' => trim((string) ($editor->translation_api_key ?? '')) !== '',
        ];
    }

    /**
     * Set the monthly ceiling of the shared translation route for the
     * open editor, or clear it back to the deployment default
     * (SPEC 5.7). Applies at once to the current month: what was
     * already spent stays counted.
     */
    public function saveTranslationCeiling(): void
    {
        $this->requireSuperAdmin();

        $editor = Editor::query()->findOrFail($this->editorId);

        $this->validate([
            'editorTranslationCeiling' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);

        $typed = trim($this->editorTranslationCeiling);
        $previous = $editor->translation_monthly_characters;

        $editor->translation_monthly_characters = $typed === '' ? null : (int) $typed;
        $editor->save();

        app(AuditLogger::class)->log('editor.translation_ceiling_changed', $editor, [
            'editor_slug' => $editor->slug,
            'previous' => $previous,
            'ceiling' => $editor->translation_monthly_characters,
        ]);

        $this->success($editor->translation_monthly_characters === null
            ? __('Volume de traduction ramené à la valeur par défaut.')
            : __('Volume de traduction enregistré.'));
    }

    /**
     * Publication quota of the open editor (SPEC 5.3): what applies now,
     * the deployment defaults, and the raise if one is running. Null
     * while no editor is open.
     *
     * @return array{capacity: int, ceiling: int, default_capacity: int, default_ceiling: int, until: string|null, pending: int, blocked: bool}|null
     */
    public function quotaState(): ?array
    {
        $editor = $this->editorTarget();

        if ($editor === null) {
            return null;
        }

        $quota = app(PublicationQuotaService::class);
        $actor = auth('web')->user();

        return [
            'capacity' => $quota->capacityFor($editor),
            'ceiling' => $quota->queueCeilingFor($editor),
            'default_capacity' => $quota->defaultCapacity(),
            'default_ceiling' => $quota->defaultQueueCeiling(),
            'until' => $editor->quotaOverrideAppliesAt(now())
                ? $editor->quota_override_until?->format('d/m/Y')
                : null,
            'pending' => $quota->pendingCount($editor),
            // Raising the quota of one's own editor favours oneself
            // (SPEC 9.6): the form is not offered, the service refuses.
            'blocked' => $actor instanceof User && app(EditorService::class)->isMember($editor, $actor),
        ];
    }

    /**
     * Raise the publication quota of the open editor until a date
     * (SPEC 5.3), with a motive. Refused on an editor the super admin
     * belongs to (SPEC 9.6).
     */
    public function saveQuotaRaise(): void
    {
        $actor = $this->requireSuperAdmin();

        $editor = Editor::query()->findOrFail($this->editorId);

        $this->validate([
            'editorQuotaCapacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'editorQuotaCeiling' => ['nullable', 'integer', 'min:1', 'max:500'],
            'editorQuotaUntil' => ['required', 'date_format:Y-m-d'],
            'editorQuotaMotive' => ['required', 'string', 'max:500'],
        ]);

        $capacity = trim($this->editorQuotaCapacity);
        $ceiling = trim($this->editorQuotaCeiling);
        $day = Carbon::createFromFormat('Y-m-d', $this->editorQuotaUntil);

        if ($day === null) {
            Log::warning('EditorList: unreadable quota end date', ['typed' => $this->editorQuotaUntil]);
            $this->addError('editorQuotaUntil', __('Date illisible.'));

            return;
        }

        try {
            app(EditorService::class)->raiseQuota(
                $editor,
                $actor,
                $capacity === '' ? null : (int) $capacity,
                $ceiling === '' ? null : (int) $ceiling,
                // The whole of the chosen day.
                $day->copy()->endOfDay(),
                $this->editorQuotaMotive,
            );
        } catch (EditorException $e) {
            Log::info('EditorList: quota raise refused', ['editor_id' => $editor->getKey(), 'reason' => $e->getMessage()]);
            $this->addError('editorQuotaUntil', $e->getMessage());

            return;
        }

        $this->editorQuotaMotive = '';
        $this->success(__('Quota de publication relevé jusqu\'au :date.', [
            'date' => $day->format('d/m/Y'),
        ]));
    }

    /**
     * End the running raise of the open editor now: the deployment
     * defaults apply again from this instant.
     */
    public function endQuotaRaise(): void
    {
        $actor = $this->requireSuperAdmin();

        $editor = Editor::query()->findOrFail($this->editorId);

        try {
            app(EditorService::class)->endQuotaRaise($editor, $actor);
        } catch (EditorException $e) {
            Log::info('EditorList: quota raise end refused', ['editor_id' => $editor->getKey(), 'reason' => $e->getMessage()]);
            $this->addError('editorQuotaUntil', $e->getMessage());

            return;
        }

        $this->loadQuotaRaise($editor->refresh());
        $this->success(__('Quota de publication ramené aux valeurs communes.'));
    }

    /**
     * Fill the quota form with the running raise, blank when none runs.
     */
    private function loadQuotaRaise(Editor $editor): void
    {
        $running = $editor->quotaOverrideAppliesAt(now());

        $this->editorQuotaCapacity = $running && $editor->quota_bucket_capacity !== null
            ? (string) $editor->quota_bucket_capacity
            : '';
        $this->editorQuotaCeiling = $running && $editor->quota_queue_ceiling !== null
            ? (string) $editor->quota_queue_ceiling
            : '';
        $this->editorQuotaUntil = $running ? (string) $editor->quota_override_until?->format('Y-m-d') : '';
        $this->editorQuotaMotive = '';
    }

    /**
     * Attach an account to the open editor as a member.
     */
    public function addMember(): void
    {
        $this->requireSuperAdmin();

        $editor = Editor::query()->findOrFail($this->editorId);

        $this->validate([
            'editorMemberEmail' => ['required', 'email'],
        ]);

        $member = $this->accountByEmail($this->editorMemberEmail, 'editorMemberEmail');

        if ($member === null) {
            return;
        }

        try {
            app(EditorService::class)->setRole($editor, $member, EditorRole::MEMBER);
        } catch (EditorException $e) {
            $this->addError('editorMemberEmail', $e->getMessage());

            return;
        }

        app(AuditLogger::class)->log('editor.member_attached', $editor, [
            'editor_slug' => $editor->slug,
            'member_user_id' => $member->getKey(),
        ]);

        $this->editorMemberEmail = '';
        $this->success(__('Compte rattaché à l\'éditeur.'));
    }

    /**
     * Promote a member to owner, or demote an owner to member.
     */
    public function setMemberRole(int $userId, string $role): void
    {
        $this->requireSuperAdmin();

        $editor = Editor::query()->findOrFail($this->editorId);
        $member = User::query()->findOrFail($userId);

        $target = EditorRole::tryFrom($role);

        abort_if($target === null, 404);

        try {
            app(EditorService::class)->setRole($editor, $member, $target);
        } catch (EditorException $e) {
            $this->error($e->getMessage());

            return;
        }

        app(AuditLogger::class)->log('editor.role_changed', $editor, [
            'editor_slug' => $editor->slug,
            'member_user_id' => $member->getKey(),
            'role' => $target->value,
        ]);

        $this->success($target === EditorRole::OWNER
            ? __('Compte promu propriétaire de l\'éditeur.')
            : __('Compte rétrogradé en membre de l\'éditeur.'));
    }

    /**
     * Detach an account from the open editor.
     */
    public function detachMember(int $userId): void
    {
        $this->requireSuperAdmin();

        $editor = Editor::query()->findOrFail($this->editorId);
        $member = User::query()->findOrFail($userId);

        try {
            app(EditorService::class)->detachMember($editor, $member);
        } catch (EditorException $e) {
            $this->error($e->getMessage());

            return;
        }

        app(AuditLogger::class)->log('editor.member_detached', $editor, [
            'editor_slug' => $editor->slug,
            'member_user_id' => $member->getKey(),
        ]);

        $this->success(__('Compte retiré de l\'éditeur.'));
    }

    /**
     * Manual validation of an editor (SPEC 3.3): sets verified_at.
     */
    public function validateEditor(int $editorId): void
    {
        if (($refusal = $this->impersonationRefusal('editor.validate')) !== null) {
            $this->error($refusal);

            return;
        }

        $editor = Editor::query()->findOrFail($editorId);

        if ($editor->verified_at === null) {
            $editor->verified_at = now();
            $editor->save();

            // SPEC 9.4: an act that changes the state of the service is
            // traceable, validating an editor included.
            app(AuditLogger::class)->log('editor.validated', $editor, [
                'editor_slug' => $editor->slug,
            ]);

            $this->success(__('Éditeur validé.'));

            return;
        }

        $this->warning(__('Cet éditeur était déjà validé.'));
    }

    /**
     * Withdraw the validation of an editor: verified_at goes back to
     * null, so the public sheet stops showing the marker. Nothing is
     * unpublished -- the announcements of a validated editor were judged
     * on their own by the review, not on its marker.
     *
     * Reserved to the super admin where validating is open to the team:
     * withdrawing a marker a third party relies on weighs more than
     * granting it.
     */
    public function unvalidateEditor(int $editorId): void
    {
        $this->requireSuperAdmin();

        $editor = Editor::query()->findOrFail($editorId);

        if ($editor->verified_at !== null) {
            $editor->verified_at = null;
            $editor->save();

            app(AuditLogger::class)->log('editor.unvalidated', $editor, [
                'editor_slug' => $editor->slug,
            ]);

            $this->success(__('Validation retirée.'));

            return;
        }

        $this->warning(__('Cet éditeur n\'était pas validé.'));
    }

    /**
     * Create the editor under the designated owner.
     *
     * Owning an editor is a way of writing, so the owner has to be a
     * contributor account (SPEC 3.1): the account screen qualifies it
     * first, by proof or by manual validation (SPEC 3.3).
     */
    private function createEditor(): void
    {
        $owner = $this->accountByEmail($this->editorOwnerEmail, 'editorOwnerEmail');

        if ($owner === null) {
            return;
        }

        if (! $owner->isContributor()) {
            $this->addError('editorOwnerEmail', __('Ce compte n\'est pas contributeur : qualifiez-le sur l\'écran des comptes avant de lui confier un éditeur.'));

            return;
        }

        try {
            $editor = app(EditorService::class)->create($owner, [
                'name' => $this->editorName,
                'description' => $this->nullIfBlank($this->editorDescription),
                'website' => $this->nullIfBlank($this->editorWebsite),
                'contact_email' => $this->editorContactEmail,
            ]);
        } catch (EditorException $e) {
            $this->addError('editorOwnerEmail', $e->getMessage());

            return;
        }

        app(AuditLogger::class)->log('editor.created', $editor, [
            'editor_slug' => $editor->slug,
            'owner_user_id' => $owner->getKey(),
        ]);

        // Straight on to the editor just created: its membership is the
        // next thing the operator came for.
        $this->openEditor($editor->getKey());
        $this->success(__('Éditeur créé.'));
    }

    /**
     * The account holding this address, or null with the error already
     * placed on the field.
     */
    private function accountByEmail(string $email, string $field): ?User
    {
        /** @var User|null $account */
        $account = User::query()
            ->where('email', mb_strtolower(trim($email)))
            ->first();

        if ($account === null) {
            $this->addError($field, __('Aucun compte avec cette adresse.'));
        }

        return $account;
    }

    /**
     * An empty form field is an absent value, not an empty string: both
     * columns are nullable, and '' would print as a blank website link.
     */
    private function nullIfBlank(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * The acting super admin, or a 403. The moderation team reaches the
     * screen and validates an editor (SPEC 9.1); the sheet and its
     * membership are the operator's.
     */
    private function requireSuperAdmin(): User
    {
        /** @var User|null $actor */
        $actor = auth('web')->user();

        abort_unless($actor instanceof User && $actor->is_super_admin, 403);

        return $actor;
    }
}

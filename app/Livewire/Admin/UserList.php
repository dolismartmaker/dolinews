<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Core\Admin\Livewire\BaseListComponent;
use App\Domain\Dolinews\Moderation\ModerationService;
use App\Livewire\Admin\Commands\SwitchToUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Account list (thin, S15): columns and query only, plus the super
 * admin's impersonation entry point. Suspensions and proof revocations
 * are moderation acts: they live in the moderation log and are acted on
 * from here with a motive and a rule reference.
 */
class UserList extends BaseListComponent
{
    public string $sortField = 'id';

    public string $sortDir = 'desc';

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

    public function mount(): void
    {
        $this->mountAuthorizeAdmin();
    }

    /**
     * @return Builder<User>
     */
    protected function baseQuery(): Builder
    {
        return User::query()->select('users.*');
    }

    /**
     * @return list<array{key: string, label: string, sortable: bool, searchable: bool}>
     */
    protected function columns(): array
    {
        return [
            ['key' => 'id', 'label' => 'Id', 'sortable' => true, 'searchable' => false],
            ['key' => 'email', 'label' => 'Email', 'sortable' => true, 'searchable' => true],
            ['key' => 'name', 'label' => 'Nom', 'sortable' => true, 'searchable' => true],
            ['key' => 'is_moderator', 'label' => 'Modérateur', 'sortable' => true, 'searchable' => false],
            ['key' => 'is_super_admin', 'label' => 'Super admin', 'sortable' => true, 'searchable' => false],
            ['key' => 'active', 'label' => 'Actif', 'sortable' => true, 'searchable' => false],
            ['key' => 'email_verified_at', 'label' => 'Email vérifié le', 'sortable' => true, 'searchable' => false],
        ];
    }

    public function actions(): array
    {
        $actions = [];

        $user = auth('web')->user();

        if ($user instanceof User && $user->is_super_admin) {
            $actions[] = ['label' => 'Basculer vers', 'method' => 'switchTo', 'class' => 'btn-impersonate'];
            $actions[] = ['label' => 'Suspendre', 'method' => 'openAct'];
        }

        return $actions;
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
    }

    /**
     * Suspend the account: a withdrawal act, never blocked by a
     * conflict of interest, confirmed within seven days when taken in
     * one (SPEC 9.6).
     */
    public function suspend(): void
    {
        /** @var User|null $actor */
        $actor = auth('web')->user();

        abort_unless($actor instanceof User && $actor->is_super_admin, 403);

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
    }

    /**
     * Restore a suspended account.
     */
    public function unsuspend(int $userId): void
    {
        /** @var User|null $actor */
        $actor = auth('web')->user();

        abort_unless($actor instanceof User && $actor->is_super_admin, 403);

        $target = User::query()->findOrFail($userId);

        if (! $target->active) {
            $target->active = true;
            $target->save();
        }
    }

    /**
     * Toggle the moderator flag (team composition, SPEC 9.1). Reaching
     * the floor of six closes the bootstrap phase for good (SPEC 5.1).
     */
    public function toggleModerator(int $userId): void
    {
        /** @var User|null $actor */
        $actor = auth('web')->user();

        abort_unless($actor instanceof User && $actor->is_super_admin, 403);

        $target = User::query()->findOrFail($userId);
        $target->is_moderator = ! $target->is_moderator;
        $target->save();
    }

    public function switchTo(int $userId): mixed
    {
        return app(SwitchToUser::class)->switchTo($userId);
    }
}

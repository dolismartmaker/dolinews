<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Commands;

use App\Core\Admin\Concerns\AuthorizesAdmin;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Row-level instance action on UserList (lab404/laravel-impersonate).
 *
 * Receives the target User id from the caller (UserList instance action) and
 * asks the ImpersonateManager to perform the session swap. The target's
 * canBeImpersonated() (User model) is enforced here for defense in depth:
 * the actor must be a super_admin, the target must be a non-admin end-user,
 * and the actor must not already be impersonating (avoids stacked
 * impersonation).
 *
 * Not a Livewire Component on purpose: it is invoked as an instance action
 * (callable) from UserList, both of which expect a plain RedirectResponse.
 *
 * Registered route: GET /admin/impersonate/take/{id} (lab404 macro).
 */
class SwitchToUser
{
    use AuthorizesAdmin;

    /**
     * Start impersonating the given user.
     *
     * Calls the ImpersonateManager directly rather than redirecting to the
     * package's GET take route, so this method works as an instance action
     * without forcing a second round trip. Returns a redirect to the admin
     * dashboard so the admin lands on a known screen post-swap.
     */
    public function switchTo(int $userId): RedirectResponse
    {
        $actor = $this->currentAdmin();

        $this->mountAuthorizeAdmin();

        if (! $actor->canImpersonate()) {
            abort(403, 'Seuls les super_admin peuvent basculer vers un utilisateur.');
        }

        if ($actor->isImpersonated()) {
            abort(403, 'Quittez l\'impersonation en cours avant d\'en démarrer une autre.');
        }

        $target = User::query()->findOrFail($userId);

        if (! $target->canBeImpersonated()) {
            abort(403, 'Cet utilisateur ne peut pas être impersonné.');
        }

        if ($target->getKey() === $actor->getKey()) {
            abort(403, 'Impossible de basculer vers votre propre compte.');
        }

        app('impersonate')->take($actor, $target);

        // Livewire 3 hijacks both `redirect()` and the `Redirect` facade to
        // return its own store-based Redirector when called from a component.
        // Build a plain RedirectResponse directly so the return type stays
        // a real RedirectResponse regardless of caller context.
        return new RedirectResponse(route('admin.dashboard'));
    }

    /**
     * The authenticated admin triggering the switch.
     *
     * The 'admin' route middleware and the explicit mountAuthorizeAdmin()
     * call guarantee an authenticated admin User here; this narrows the
     * type for PHPStan.
     */
    private function currentAdmin(): User
    {
        $user = auth('web')->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}

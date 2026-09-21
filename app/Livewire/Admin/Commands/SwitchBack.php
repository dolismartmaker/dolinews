<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Commands;

use App\Core\Admin\Concerns\AuthorizesAdmin;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Action to leave a live impersonation (lab404/laravel-impersonate).
 *
 * Mounted through LeaveImpersonationController as the POST endpoint for the
 * CSRF-protected "Revenir à l'administration" button rendered in the admin
 * layout banner, and called directly as an instance action from anywhere
 * else in the admin that wants to exit impersonation.
 *
 * Not a Livewire Component on purpose: the only meaningful behaviour is
 * clearing the impersonator and redirecting back to the admin dashboard.
 */
class SwitchBack
{
    use AuthorizesAdmin;

    /**
     * Leave the current impersonation and return to the original admin.
     *
     * Calls the ImpersonateManager directly rather than redirecting to the
     * package's GET route, so this method works as a POST handler or any
     * other direct caller. Returns a redirect to the admin dashboard so
     * the admin lands back on a known screen.
     *
     * No canAccessAdmin() gate here on purpose - while impersonating the
     * web guard is the impersonated user (non-admin), and the admin
     * middleware that wraps this route runs against the impersonator's
     * session, not the impersonated one. The impersonating() check below
     * is the single behavioural gate.
     */
    public function leave(): RedirectResponse
    {
        $this->currentUser();

        $manager = app('impersonate');

        if (! $manager->isImpersonating()) {
            abort(403, 'Aucune impersonation en cours.');
        }

        $manager->leave();

        // Same rationale as SwitchToUser: bypass Livewire's redirect hijacking
        // so the return type stays a plain RedirectResponse regardless of
        // whether the caller is a Livewire component or a plain controller.
        return new RedirectResponse(route('admin.dashboard'));
    }

    /**
     * The authenticated user triggering the leave.
     *
     * During impersonation the web guard is the impersonated user (the
     * target), not the original admin, so we only assert that some user is
     * authenticated. The behavioural gate is isImpersonating() above.
     */
    private function currentUser(): User
    {
        $user = auth('web')->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}

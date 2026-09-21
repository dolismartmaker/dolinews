<?php

declare(strict_types=1);

namespace App\Core\Admin\Concerns;

use App\Models\User;

/**
 * Defense-in-depth admin gating for Livewire components (S2/section 6).
 *
 * The 'admin' route middleware is the primary gate; this trait re-checks the
 * same condition from the component mount() so a component can never render
 * for a non-admin even if wired outside the protected route group.
 *
 * Impersonation (lab404/laravel-impersonate): while impersonating, the web
 * guard is the impersonated user, not the original admin. The admin back-
 * office must remain reachable (banner + "leave" action), so the gate
 * accepts the request when the ORIGINAL impersonator still has the admin
 * capability. The ImpersonateManager is the single source of truth for
 * "who is the admin behind this session".
 */
trait AuthorizesAdmin
{
    /**
     * Abort with 403 unless the current session user may reach the admin.
     *
     * Call this from the component's mount() method.
     */
    protected function mountAuthorizeAdmin(): void
    {
        $user = $this->resolveAdminUser();

        abort_unless($user instanceof User && $user->canAccessAdmin(), 403);
    }

    /**
     * The user that backs the admin capability check.
     *
     * Normal case: the web guard user. During impersonation: the original
     * admin stored in the ImpersonateManager, which lets the admin back-
     * office stay reachable while the guard acts as the impersonated user.
     */
    private function resolveAdminUser(): ?User
    {
        $user = auth()->user();

        if ($user instanceof User && $user->canAccessAdmin()) {
            return $user;
        }

        $manager = app('impersonate');

        if ($manager->isImpersonating()) {
            $impersonator = $manager->getImpersonator();

            if ($impersonator instanceof User && $impersonator->canAccessAdmin()) {
                return $impersonator;
            }
        }

        return $user;
    }
}

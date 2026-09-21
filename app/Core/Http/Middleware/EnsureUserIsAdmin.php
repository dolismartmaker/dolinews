<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard for the Livewire admin back-office (S2/section 6).
 *
 * Guests are redirected to the admin login; authenticated users who lack the
 * admin capability (unverified email or missing role) are refused with 403.
 * The web 'auth' middleware normally handles guests, but redirecting here too
 * keeps the guard self-contained and points at the admin login screen.
 *
 * Impersonation (lab404/laravel-impersonate): while impersonating an
 * end-user, the web guard is the impersonated user, not the original admin.
 * The ImpersonateManager still has the original admin in session, so we
 * accept the request as long as THAT original admin has the admin
 * capability. This keeps the admin back-office reachable (banner, "leave"
 * button) during an impersonation session.
 */
class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->resolveAdminUser($request);

        if (! $user instanceof User) {
            Log::info('EnsureUserIsAdmin: unauthenticated visitor redirected to admin login', [
                'path' => $request->path(),
            ]);

            return redirect()->route('admin.login');
        }

        if (! $user->canAccessAdmin()) {
            Log::warning('EnsureUserIsAdmin: authenticated user denied admin access', [
                'user_id' => $user->getKey(),
                'path' => $request->path(),
            ]);

            abort(403);
        }

        return $next($request);
    }

    /**
     * The user that backs the admin capability check.
     *
     * Normal case: the web guard user. During impersonation: the original
     * admin stored in the ImpersonateManager, which lets the admin back-
     * office stay reachable while the guard acts as the impersonated user.
     */
    private function resolveAdminUser(Request $request): ?User
    {
        $user = Auth::guard('web')->user();

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

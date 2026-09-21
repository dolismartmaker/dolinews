<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds an account on the password screen until it has left the
 * password it was created with.
 *
 * The seeder posts the super admin's password from the environment: a
 * value that sat in a .env, read by everyone who deployed. Without this,
 * it stays a valid credential for as long as nobody happens to change
 * it.
 */
class EnsurePasswordIsChanged
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User || ! $user->must_change_password) {
            return $next($request);
        }

        // The screen that lifts the flag, and the way out, stay open.
        if ($request->routeIs('account.password', 'account.password.update', 'logout', 'admin.logout')) {
            return $next($request);
        }

        return redirect()->route('account.password');
    }
}

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
 * Turns a suspension into an immediate loss of rights on the web
 * surface (SPEC 9.3).
 *
 * The login screen checks `active`, and so does every API call, but a
 * session opened before the suspension kept its contributor rights until
 * it expired - up to the whole session lifetime of writing, submitting
 * and uploading. The check belongs on every authenticated request, like
 * the API has it.
 *
 * An impersonation is left alone: the guard user is then the impersonated
 * account, and a super admin looking at a suspended account from the
 * inside is precisely what impersonation is for.
 */
class EnsureUserIsActive
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User || $user->active) {
            return $next($request);
        }

        if (app('impersonate')->isImpersonating()) {
            return $next($request);
        }

        Log::warning('EnsureUserIsActive: suspended account cut off mid-session', [
            'user_id' => $user->getKey(),
            'path' => $request->path(),
        ]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->withErrors(['email' => __('Ce compte est suspendu.')]);
    }
}

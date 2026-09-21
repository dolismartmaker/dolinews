<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ends the admin session and returns to the login screen (S2/section 6).
 *
 * Thin controller (S15): logs out the web guard, invalidates the session and
 * rotates the CSRF token, then redirects.
 */
class LogoutController extends Controller
{
    /**
     * Log the admin out and invalidate the session.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Password forgotten / reset flow, standard broker.
 */
class PasswordResetController extends Controller
{
    /**
     * Show the "forgot password" form.
     */
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Send the reset link.
     */
    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
            ? back()->with('status', __($status))
            : back()->withErrors(['email' => __($status)]);
    }

    /**
     * Show the reset form for a token.
     */
    public function reset(string $token): View
    {
        return view('auth.reset-password', ['token' => $token]);
    }

    /**
     * Apply the new password.
     */
    public function update(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);

        $status = Password::reset(
            $payload,
            function (User $user, string $password): void {
                $user->password = $password;
                $user->save();
            },
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', __($status))
            : back()->withErrors(['email' => __($status)]);
    }
}

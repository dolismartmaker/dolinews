<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\CredentialRevoker;
use App\Http\Controllers\Concerns\ResolvesUser;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Password change from the account, and the forced change the seeded
 * super admin lands on at its first login.
 */
class PasswordController extends Controller
{
    use ResolvesUser;

    /**
     * The change form.
     */
    public function edit(Request $request): View
    {
        $user = $this->requireUser($request);

        return view('account.password', ['forced' => $user->must_change_password]);
    }

    /**
     * Apply the new password.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $this->requireUser($request);

        $payload = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);

        if (! Hash::check((string) $payload['current_password'], (string) $user->password)) {
            return back()->withErrors(['current_password' => 'Mot de passe actuel incorrect.']);
        }

        $user->password = (string) $payload['password'];
        $user->must_change_password = false;

        // Same reasoning as the reset flow: whatever else was issued
        // under the old password does not outlive it.
        app(CredentialRevoker::class)->revokeAllExceptPassword($user);

        $user->save();

        // Re-authenticate the current session, which the revocation just
        // invalidated along with the others.
        $request->session()->regenerate();
        auth('web')->login($user);

        app(AuditLogger::class)->log('password.changed', $user);

        return redirect()->route('account.show')
            ->with('status', 'Mot de passe mis à jour.');
    }
}

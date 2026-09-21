<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Email verification of freshly registered accounts (SPEC 3.1): a
 * one-time signed link, Laravel's built-in flow.
 */
class EmailVerificationController extends Controller
{
    /**
     * Notice screen: "check your inbox".
     */
    public function notice(): View
    {
        return view('auth.verify-email');
    }

    /**
     * Resend the verification email.
     */
    public function resend(Request $request): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('account.show');
        }

        $user->sendEmailVerificationNotification();

        return back()->with('status', 'Courriel de validation renvoyé.');
    }

    /**
     * Consume the one-time signed link (SPEC 3.1: reader accounts are
     * validated by email before their watches and feeds activate).
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        /** @var User|null $user */
        $user = User::query()->find($id);

        if ($user === null) {
            abort(403);
        }

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403);
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('account.show');
        }

        $user->markEmailAsVerified();

        return redirect()->route('account.show')
            ->with('status', 'Adresse validée : vos abonnements sont actifs.');
    }
}

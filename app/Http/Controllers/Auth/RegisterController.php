<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AccountCreated;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * Reader-account registration (SPEC 3.1): free creation, email
 * validation, no write rights. The contributor qualification happens
 * later, from the account page: authentication and qualification are
 * distinct by design.
 */
class RegisterController extends Controller
{
    /**
     * Show the registration form.
     */
    public function show(): View
    {
        return view('auth.register');
    }

    /**
     * Create the reader account and send the validation email.
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = User::query()->create([
            'name' => $payload['name'],
            'email' => mb_strtolower(trim($payload['email'])),
            'password' => $payload['password'],
            'email_verified_at' => null,
            'active' => true,
        ]);

        $user->sendEmailVerificationNotification();

        // The operator watches account creation (SPEC 9.1): the moderation
        // team is not in the loop here, a reader account carries no write
        // right and nothing to review.
        User::query()
            ->superAdmins()
            ->get()
            ->each(fn (User $admin) => $admin->notify(new AccountCreated($user)));

        return redirect()->route('verification.notice')
            ->with('status', __('Compte créé : validez votre adresse pour activer vos abonnements.'));
    }
}

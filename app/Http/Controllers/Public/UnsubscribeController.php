<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Dolinews\Subscriptions\EmailSubscriptionService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Leaving the subscription mails from the mail itself (SPEC 6.4).
 *
 * No session and no password: the reader who wants out has the mail in
 * front of them, not their credentials. The token is the credential,
 * exactly like the personal feed URL, and it only ever grants this one
 * act - stopping the mails. Watches, account and personal feed are
 * untouched.
 */
class UnsubscribeController extends Controller
{
    public function __construct(
        private readonly EmailSubscriptionService $subscriptions,
    ) {}

    /**
     * The confirmation page the footer link points at: a link followed
     * by a mail client's link scanner must not unsubscribe anyone, so
     * the act needs the button below, not the click that got here.
     */
    public function show(string $token): View
    {
        $user = $this->subscriptions->byUnsubscribeToken($token);

        if ($user === null) {
            abort(404);
        }

        return view('public.unsubscribe', [
            'token' => $token,
            'email' => $user->email,
            'done' => false,
        ]);
    }

    /**
     * The act itself, also reached by the RFC 8058 one-click header
     * without a CSRF token: a mail client posts here on its own.
     */
    public function store(string $token): View
    {
        $user = $this->subscriptions->byUnsubscribeToken($token);

        if ($user === null) {
            abort(404);
        }

        $this->subscriptions->unsubscribe($user);

        return view('public.unsubscribe', [
            'token' => $token,
            'email' => $user->email,
            'done' => true,
        ]);
    }
}

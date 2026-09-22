<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Dolinews\Enums\EmailDigest;
use App\Domain\Dolinews\Enums\Focus;
use App\Domain\Dolinews\Enums\Maturity;
use App\Domain\Dolinews\Subscriptions\EmailSubscriptionService;
use App\Domain\Dolinews\Subscriptions\WatchService;
use App\Http\Controllers\Concerns\ResolvesUser;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The reader's account page: profile, personal feed URL and watches.
 */
class AccountController extends Controller
{
    use ResolvesUser;

    public function __construct(
        private readonly WatchService $watches,
        private readonly EmailSubscriptionService $emailSubscriptions,
    ) {}

    /**
     * Profile and personal feed summary.
     */
    public function show(Request $request): View
    {
        $user = $this->requireUser($request);

        return view('account.show', [
            'user' => $user,
            'feedUrl' => $user->feed_token !== null
                ? route('feeds.personal', ['token' => $user->feed_token])
                : null,
            'projectWatches' => $user->projectWatches()->with('project')->get(),
            'editorWatches' => $user->editorWatches()->with('editor')->get(),
            'digestChoices' => EmailDigest::sending(),
        ]);
    }

    /**
     * Mail subscription preferences (SPEC 6.4): cadence, the whole-feed
     * watch, and the security-only filter that keeps it readable.
     *
     * The cadence belongs to the reader: an integrator wants a security
     * fix within the hour, a director wants one mail a week and leaves
     * over anything noisier.
     */
    public function updateEmail(Request $request): RedirectResponse
    {
        $user = $this->requireUser($request);

        $payload = $request->validate([
            'email_digest' => ['required', Rule::enum(EmailDigest::class)],
            'watches_all' => ['nullable', 'boolean'],
            'focus' => ['nullable', 'array'],
            'focus.*' => [Rule::enum(Focus::class)],
            'maturity' => ['nullable', 'array'],
            'maturity.*' => [Rule::enum(Maturity::class)],
        ]);

        $this->emailSubscriptions->updatePreferences(
            $user,
            EmailDigest::from((string) $payload['email_digest']),
            [
                'watches_all' => (bool) ($payload['watches_all'] ?? false),
                'focus' => array_values((array) ($payload['focus'] ?? [])) ?: null,
                'maturities' => array_values((array) ($payload['maturity'] ?? [])) ?: null,
                // The mails speak the language the account was reading
                // when it subscribed: a scheduled command has no session
                // to read it from later.
                'locale' => app()->getLocale(),
            ],
        );

        return back()->with('status', __('Préférences de courriel enregistrées.'));
    }

    /**
     * Update the public profile fields.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $this->requireUser($request);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
        ]);

        $user->fill($payload)->save();

        return back()->with('status', __('Profil mis à jour.'));
    }

    /**
     * Issue the personal feed token (SPEC 6.4).
     */
    public function issueFeedToken(Request $request): RedirectResponse
    {
        $this->watches->issueFeedToken($this->requireUser($request));

        return back()->with('status', __('Flux personnel créé.'));
    }

    /**
     * Revoke the personal feed token by regeneration: the old URL dies
     * on the spot (SPEC 6.4).
     */
    public function regenerateFeedToken(Request $request): RedirectResponse
    {
        $this->watches->regenerateFeedToken($this->requireUser($request));

        return back()->with('status', __('Flux personnel régénéré : l\'ancienne URL est révoquée.'));
    }
}

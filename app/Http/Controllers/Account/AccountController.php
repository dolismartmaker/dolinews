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
            'contentLocales' => (array) config('dolinews.content_locales', []),
            'localeNames' => (array) config('dolinews.locale_names', []),
        ]);
    }

    /**
     * Mail subscription preferences (SPEC 6.4): cadence, the whole-feed
     * watch, and the security watch that spans every project.
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
            'watches_all_security' => ['nullable', 'boolean'],
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
                'watches_all_security' => (bool) ($payload['watches_all_security'] ?? false),
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
     * The content languages a moderator declares reading (SPEC 5.1).
     *
     * It drives who receives the circuit mails, never who may review:
     * the queue stays open to the whole team. An emptied selection reads
     * as "every language", the same way an editor's translation
     * languages do (SPEC 5.7) - someone who unchecks everything is
     * resetting, not resigning.
     */
    public function updateReviewLocales(Request $request): RedirectResponse
    {
        $user = $this->requireUser($request);

        if (! $user->inReviewTeam()) {
            abort(403);
        }

        $payload = $request->validate([
            'review_locales' => ['nullable', 'array'],
            'review_locales.*' => ['string', Rule::in(config('dolinews.content_locales', []))],
        ]);

        $selected = array_values(array_unique((array) ($payload['review_locales'] ?? [])));

        $user->review_locales = $selected === [] ? null : $selected;
        // The circuit mails speak this account's language, and the only
        // place that language is stored is this column: the interface
        // locale lives in the session, which no queued notification can
        // read. A moderator sets this screen in the language they read,
        // so this is where the two meet.
        $user->locale = app()->getLocale();
        $user->save();

        return back()->with('status', $selected === []
            ? __('Langues de revue enregistrées : toutes les langues.')
            : __('Langues de revue enregistrées.'));
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Dolinews\Enums\EmailDigest;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Subscriptions\EmailSubscriptionService;
use App\Domain\Dolinews\Subscriptions\PublicSubscriptionService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Subscribing, and managing the subscription, without an account
 * (SPEC 6.4).
 *
 * The reader types an address on a project or editor sheet and clicks
 * the link they receive. Everything else - the account, the watch, the
 * cadence - happens behind them, and they never meet a password or a
 * back-office.
 *
 * Preferences open by token and never by session: the same table
 * carries the readers, the contributors and the moderators, so a mail
 * link that logged its recipient in would hand a publishing account to
 * whoever reads that mailbox. The token grants the subscription screen,
 * nothing more.
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly PublicSubscriptionService $subscriptions,
        private readonly EmailSubscriptionService $emails,
    ) {}

    /**
     * Take an address from a project sheet.
     */
    public function storeProject(Request $request, string $slug): RedirectResponse
    {
        /** @var Project|null $project */
        $project = Project::query()->where('slug', $slug)->first();

        abort_if($project === null, 404);

        $payload = $this->validated($request);

        if ($payload !== null) {
            $this->subscriptions->requestSubscription(
                $payload['email'],
                project: $project,
                securityOnly: $payload['security'],
            );
        }

        return redirect()
            ->route('projects.show', ['slug' => $project->slug])
            ->with('subscribed', true);
    }

    /**
     * Take an address from an editor page.
     */
    public function storeEditor(Request $request, string $slug): RedirectResponse
    {
        /** @var Editor|null $editor */
        $editor = Editor::query()->where('slug', $slug)->first();

        abort_if($editor === null, 404);

        $payload = $this->validated($request);

        if ($payload !== null) {
            $this->subscriptions->requestSubscription(
                $payload['email'],
                editor: $editor,
                securityOnly: $payload['security'],
            );
        }

        return redirect()
            ->route('editors.show', ['slug' => $editor->slug])
            ->with('subscribed', true);
    }

    /**
     * The confirmation page the mail points at. The act needs the button
     * below: a mail client's link scanner follows every address it finds,
     * and a subscription a machine can confirm is a subscription nobody
     * confirmed.
     */
    public function confirm(string $token): View
    {
        return view('public.subscribe-confirm', [
            'token' => $token,
            'done' => false,
            'failed' => false,
        ]);
    }

    /**
     * The confirmation itself: from here on, the address is subscribed.
     */
    public function storeConfirmation(string $token): View
    {
        $user = $this->subscriptions->confirm($token);

        return view('public.subscribe-confirm', [
            'token' => $token,
            'done' => $user !== null,
            'failed' => $user === null,
        ]);
    }

    /**
     * The page that mails a preferences link: the way back in for a
     * subscriber who has no password and no session.
     */
    public function preferencesRequest(): View
    {
        return view('public.preferences-request', ['sent' => false]);
    }

    /**
     * Mail the link. The answer is the same whether the address is known
     * or not: this form is public, and a different answer would tell a
     * stranger who subscribed here.
     */
    public function storePreferencesRequest(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        if (($payload['website'] ?? '') !== '') {
            Log::warning('SubscriptionController: bait field filled, preferences link dropped', [
                'ip' => $request->ip(),
            ]);

            return redirect()->route('subscriptions.preferences.request')->with('sent', true);
        }

        $this->subscriptions->requestManageLink((string) $payload['email']);

        return redirect()->route('subscriptions.preferences.request')->with('sent', true);
    }

    /**
     * The preferences of one subscriber, opened by token.
     */
    public function preferences(string $token): View
    {
        $user = $this->account($token);

        return view('public.preferences', [
            'token' => $token,
            'user' => $user,
            'digestChoices' => array_values(array_filter(
                EmailDigest::cases(),
                static fn (EmailDigest $case): bool => $case !== EmailDigest::NONE,
            )),
            'projectWatches' => $user->projectWatches()->with('project')->get(),
            'editorWatches' => $user->editorWatches()->with('editor')->get(),
        ]);
    }

    /**
     * Write them back.
     */
    public function updatePreferences(Request $request, string $token): RedirectResponse
    {
        $user = $this->account($token);

        $payload = $request->validate([
            'email_digest' => ['required', Rule::enum(EmailDigest::class)],
            'watches_all' => ['nullable', 'boolean'],
            'watches_all_security' => ['nullable', 'boolean'],
        ]);

        $this->emails->updatePreferences(
            $user,
            EmailDigest::from((string) $payload['email_digest']),
            [
                'watches_all' => (bool) ($payload['watches_all'] ?? false),
                'watches_all_security' => (bool) ($payload['watches_all_security'] ?? false),
                'locale' => app()->getLocale(),
            ],
        );

        return redirect()
            ->route('subscriptions.preferences', ['token' => $token])
            ->with('status', __('Préférences enregistrées.'));
    }

    /**
     * Drop one watch from the preferences page.
     */
    public function destroyWatch(Request $request, string $token): RedirectResponse
    {
        $user = $this->account($token);

        $payload = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'editor_id' => ['nullable', 'integer'],
        ]);

        if (isset($payload['project_id'])) {
            $user->projectWatches()->where('project_id', $payload['project_id'])->delete();
        }

        if (isset($payload['editor_id'])) {
            $user->editorWatches()->where('editor_id', $payload['editor_id'])->delete();
        }

        return redirect()
            ->route('subscriptions.preferences', ['token' => $token])
            ->with('status', __('Abonnement retiré.'));
    }

    /**
     * The account a token opens, or a 404: an expired link must not say
     * whose address it carried.
     */
    private function account(string $token): User
    {
        $user = $this->subscriptions->accountForManageLink($token);

        if ($user === null) {
            Log::info('SubscriptionController: preferences refused, link not pending');

            abort(404);
        }

        return $user;
    }

    /**
     * Validate the subscription form, and return null when the bait
     * field was filled. Silent for the sender, logged for us: a
     * legitimate reader tripped up by an autofill extension would
     * otherwise vanish without a trace.
     *
     * @return array{email: string, security: bool}|null
     */
    private function validated(Request $request): ?array
    {
        $payload = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'security' => ['nullable', 'boolean'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        if (($payload['website'] ?? '') !== '') {
            Log::warning('SubscriptionController: bait field filled, subscription dropped', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return null;
        }

        return [
            'email' => (string) $payload['email'],
            'security' => (bool) ($payload['security'] ?? false),
        ];
    }
}

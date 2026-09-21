<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Dolinews\Subscriptions\WatchService;
use App\Http\Controllers\Concerns\ResolvesUser;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The reader's account page: profile, personal feed URL and watches.
 */
class AccountController extends Controller
{
    use ResolvesUser;

    public function __construct(
        private readonly WatchService $watches,
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
        ]);
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

        return back()->with('status', 'Profil mis à jour.');
    }

    /**
     * Issue the personal feed token (SPEC 6.4).
     */
    public function issueFeedToken(Request $request): RedirectResponse
    {
        $this->watches->issueFeedToken($this->requireUser($request));

        return back()->with('status', 'Flux personnel créé.');
    }

    /**
     * Revoke the personal feed token by regeneration: the old URL dies
     * on the spot (SPEC 6.4).
     */
    public function regenerateFeedToken(Request $request): RedirectResponse
    {
        $this->watches->regenerateFeedToken($this->requireUser($request));

        return back()->with('status', 'Flux personnel régénéré : l\'ancienne URL est révoquée.');
    }
}

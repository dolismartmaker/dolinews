<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\TokenLifetime;
use App\Http\Controllers\Concerns\ResolvesUser;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Personal API tokens (SPEC 5.2): created from the account, meant to
 * sit in an integration chain. The plaintext is shown exactly once.
 */
class TokenController extends Controller
{
    use ResolvesUser;

    /**
     * List the account's tokens.
     */
    public function index(Request $request): View
    {
        $user = $this->requireUser($request);

        return view('account.tokens', [
            'tokens' => $user->tokens()
                ->orderByDesc('created_at')
                ->get()
                ->each(fn (PersonalAccessToken $token) => $token->last_used_at?->diffForHumans()),
        ]);
    }

    /**
     * Mint a token: the plaintext is returned once, then only its name
     * and metadata remain.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $this->requireUser($request);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        // The term is written on the row, not only enforced by the
        // guard: the account page has to be able to show it.
        $token = $user->createToken(
            (string) $payload['name'],
            ['*'],
            TokenLifetime::expiresAt(),
        );

        app(AuditLogger::class)->log('token.created', $user, ['name' => (string) $payload['name']]);

        return redirect()->route('account.tokens')
            ->with('newToken', $token->plainTextToken)
            ->with('status', __('Jeton créé : copiez-le maintenant, il ne sera plus affiché.'));
    }

    /**
     * Revoke a token.
     */
    public function destroy(Request $request, int $tokenId): RedirectResponse
    {
        $user = $this->requireUser($request);

        $deleted = $user->tokens()->where('id', $tokenId)->delete();

        if ($deleted === 0) {
            return back()->withErrors(['token' => 'Jeton introuvable.']);
        }

        app(AuditLogger::class)->log('token.revoked', $user, ['token_id' => $tokenId]);

        return back()->with('status', __('Jeton révoqué.'));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Core\Audit\AuditLogger;
use App\Domain\Dolinews\Contributors\ContributorVerificationException;
use App\Domain\Dolinews\Contributors\ContributorVerificationService;
use App\Domain\Dolinews\Support\CommitterEmailHasher;
use App\Http\Controllers\Concerns\ResolvesUser;
use App\Http\Controllers\Controller;
use App\Mail\ContributionCode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Contributor qualification from the account page (SPEC 3).
 *
 * Authentication and qualification are distinct on purpose: the account
 * exists first, the proof of contribution comes second, by email code
 * (simple level) or GPG-signed challenge (strong level).
 */
class ContributionController extends Controller
{
    use ResolvesUser;

    public function __construct(
        private readonly ContributorVerificationService $verification,
    ) {}

    /**
     * The qualification form and current proofs.
     */
    public function show(Request $request): View
    {
        $user = $this->requireUser($request);

        return view('account.contribute', [
            'proofs' => $user->proofs()->orderByDesc('verified_at')->get(),
            'isContributor' => $user->isContributor(),
            // A qualified account still needs an editor to publish
            // under: the page carries that step rather than leaving the
            // contributor on a dead end.
            'hasEditor' => $user->editors()->exists(),
        ]);
    }

    /**
     * Step one: the candidate declares a commit address and chooses a
     * verification level. The service only ever sees the peppered hash.
     */
    public function start(Request $request): RedirectResponse
    {
        $user = $this->requireUser($request);

        $payload = $request->validate([
            'commit_address' => ['required', 'email', 'max:255'],
            'method' => ['required', 'in:email,gpg'],
        ]);

        $address = CommitterEmailHasher::normalise((string) $payload['commit_address']);
        $known = $this->verification->lookup($address);

        if ($known === null) {
            // Unknown to every reference repository: the manual path is
            // the only one left (SPEC 3.3, editors without public
            // repository).
            return back()->withErrors([
                'commit_address' => 'Cette adresse n\'apparaît dans aucun dépôt de référence. '
                    .'Demandez la validation manuelle de l\'équipe de modération depuis cette page.',
            ])->onlyInput('commit_address');
        }

        if ($payload['method'] === 'gpg' || CommitterEmailHasher::isAnonymisedRedirect($address)) {
            // Anonymised redirects cannot receive mail: only the strong
            // level or manual validation remain (SPEC 3.3).
            $challenge = $this->verification->issueGpgChallenge($user, $address);

            if ($challenge === null) {
                return back()->withErrors([
                    'commit_address' => 'Défi GPG indisponible, réessayez.',
                ]);
            }

            return redirect()->route('account.contribute')
                ->with('gpgChallenge', $challenge['challenge']);
        }

        $code = $this->verification->issueEmailChallenge($user, $address);

        if ($code === null) {
            return back()->withErrors([
                'commit_address' => 'Adresse introuvable dans l\'index des contributeurs.',
            ]);
        }

        Mail::to($address)->send(new ContributionCode($code));

        return redirect()->route('account.contribute')
            ->with('status', 'Un code à usage unique a été envoyé à votre adresse de commit.');
    }

    /**
     * Step two (simple level): the candidate types the received code.
     */
    public function verifyCode(Request $request): RedirectResponse
    {
        $user = $this->requireUser($request);

        $payload = $request->validate(['code' => ['required', 'digits:6']]);

        try {
            $this->verification->verifyEmailCode($user, (string) $payload['code']);
        } catch (ContributorVerificationException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()->route('account.contribute')
            ->with('status', 'Contribution vérifiée : votre compte peut désormais publier.');
    }

    /**
     * Step two (strong level): the candidate pastes their public key and
     * the signature of the challenge.
     */
    public function verifyGpg(Request $request): RedirectResponse
    {
        $user = $this->requireUser($request);

        $payload = $request->validate([
            'public_key' => ['required', 'string', 'max:20000'],
            'signature' => ['required', 'string', 'max:20000'],
        ]);

        try {
            $this->verification->verifyGpgSignature(
                $user,
                (string) $payload['public_key'],
                (string) $payload['signature'],
            );
        } catch (ContributorVerificationException $e) {
            Log::info('ContributionController: GPG verification refused', [
                'user_id' => $user->getKey(),
                'reason' => $e->getMessage(),
            ]);

            return back()->withErrors(['signature' => $e->getMessage()]);
        }

        return redirect()->route('account.contribute')
            ->with('status', 'Signature vérifiée : votre compte peut désormais publier.');
    }

    /**
     * Ask the moderation team for manual validation (SPEC 3.3).
     */
    public function requestManual(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'commit_address' => ['required', 'email', 'max:255'],
            'explanation' => ['required', 'string', 'max:2000'],
        ]);

        $user = $this->requireUser($request);

        Log::info('ContributionController: manual validation requested', [
            'user_id' => $user->getKey(),
        ]);

        // The request lands in the moderation queue: a moderator grants
        // or refuses it from the admin back-office, the decision is a
        // moderation act like any other (SPEC 3.3/9.1).
        app(AuditLogger::class)->log(
            'contribution.manual_requested',
            $user,
            ['explanation' => mb_substr((string) $payload['explanation'], 0, 500)],
        );

        return redirect()->route('account.contribute')
            ->with('status', 'Demande de validation manuelle enregistrée : l\'équipe de modération va l\'instruire.');
    }
}

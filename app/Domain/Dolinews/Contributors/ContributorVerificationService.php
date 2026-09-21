<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Contributors;

use App\Domain\Dolinews\Enums\ProofMethod;
use App\Domain\Dolinews\Models\ContributorProof;
use App\Domain\Dolinews\Models\KnownCommitterHash;
use App\Domain\Dolinews\Support\CommitterEmailHasher;
use App\Models\User;
use App\Notifications\ContributorQualified;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Verifies that an account holder is a real Dolibarr ecosystem
 * contributor (SPEC 3).
 *
 * Qualification, not authentication: the account exists, this service
 * proves the person behind it authored commits. The candidate's address
 * is looked up in the peppered-hash index harvested from the reference
 * repositories, then possession of the address is proven by a one-time
 * code (simple level) or a GPG-signed challenge (strong level). The
 * moderation team can also validate manually (SPEC 3.3).
 */
class ContributorVerificationService
{
    /** Lifetime of a possession challenge, minutes. */
    private const CHALLENGE_TTL_MINUTES = 60;

    /** Failed attempts before a challenge is locked. */
    private const MAX_ATTEMPTS = 5;

    /**
     * Whether a first proof notifies the super admins.
     *
     * True for every qualification of a single account, false for the
     * duration of the catch-up pass, which qualifies in bulk after an
     * import: one email per account would drown the mailbox it is meant
     * to inform.
     */
    private bool $announceQualification = true;

    /**
     * Look up a commit address in the harvested index.
     *
     * Returns the best known row (highest commit count across reference
     * repositories) or null when the address is unknown to every
     * repository.
     */
    public function lookup(string $commitAddress): ?KnownCommitterHash
    {
        $hash = CommitterEmailHasher::hash($commitAddress);

        /** @var KnownCommitterHash|null $best */
        $best = KnownCommitterHash::query()
            ->where('email_hash', $hash)
            ->orderByDesc('commit_count')
            ->first();

        return $best;
    }

    /**
     * Qualify an account whose own verified address is a known commit
     * address (SPEC 3.2, simple level).
     *
     * This does not skip the possession proof, it reuses the one already
     * made: the account address was proven by the one-time signed link of
     * the registration flow, which is exactly the simple level. Asking
     * for a second code on the same mailbox would prove nothing new.
     *
     * Hence the hard condition: an unverified address qualifies nobody,
     * otherwise registering under a known contributor's address would
     * grant write rights outright.
     *
     * Returns the freshly created proof, or null when nothing was done:
     * unverified address, suspended account, address unknown to the
     * index, or hash already bound (SPEC 3.4). Never throws, as it runs
     * inside the email verification flow, which must not break.
     */
    public function autoLinkFromAccountAddress(User $user): ?ContributorProof
    {
        if ($user->email_verified_at === null) {
            Log::info('ContributorVerification: auto-link skipped, address not verified', [
                'user_id' => $user->getKey(),
            ]);

            return null;
        }

        // A suspended account is a moderation act in force (SPEC 9.3):
        // an automatism does not hand it write rights back.
        if (! $user->active) {
            Log::info('ContributorVerification: auto-link skipped, account suspended', [
                'user_id' => $user->getKey(),
            ]);

            return null;
        }

        try {
            $known = $this->lookup($user->email);
        } catch (\RuntimeException $e) {
            Log::error('ContributorVerification: auto-link impossible', [
                'user_id' => $user->getKey(),
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        if ($known === null) {
            return null;
        }

        // Any pre-existing proof on that hash stops the auto-link, even a
        // revoked one: a revocation is a moderation act (SPEC 9.3), an
        // automatism must never undo it.
        if (ContributorProof::query()->where('email_hash', $known->email_hash)->exists()) {
            Log::info('ContributorVerification: auto-link skipped, hash already bound', [
                'user_id' => $user->getKey(),
            ]);

            return null;
        }

        try {
            $proof = $this->createProof($user, ProofMethod::EMAIL, $known->email_hash);
        } catch (ContributorVerificationException $e) {
            Log::warning('ContributorVerification: auto-link refused', [
                'user_id' => $user->getKey(),
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        Log::info('ContributorVerification: account auto-qualified from its verified address', [
            'user_id' => $user->getKey(),
            'proof_id' => $proof->getKey(),
        ]);

        return $proof;
    }

    /**
     * Catch-up pass over accounts that were already verified before the
     * index knew their address, typically right after an import.
     *
     * Accounts already carrying a proof, revoked or not, are left alone.
     *
     * @return int accounts qualified by this pass
     */
    public function linkVerifiedAccounts(): int
    {
        $linked = 0;
        $this->announceQualification = false;

        // Paginated by id rather than offset: proofs are created as the
        // pass runs, which would shift an offset-based window under it.
        $candidates = User::query()
            ->whereNotNull('email_verified_at')
            ->whereDoesntHave('proofs')
            ->lazyById(200);

        try {
            foreach ($candidates as $user) {
                if ($this->autoLinkFromAccountAddress($user) !== null) {
                    $linked++;
                }
            }
        } finally {
            $this->announceQualification = true;
        }

        Log::info('ContributorVerification: catch-up pass done', ['linked' => $linked]);

        return $linked;
    }

    /**
     * Issue a one-time possession code for a commit address found in the
     * harvested index (SPEC 3.2, simple level).
     *
     * Returns the plaintext code for the caller to dispatch by email; it
     * is stored hashed only. Supersedes any pending challenge.
     */
    public function issueEmailChallenge(User $user, string $commitAddress): ?string
    {
        $known = $this->lookup($commitAddress);

        if ($known === null) {
            Log::info('ContributorVerification: address unknown to the reference repositories', [
                'user_id' => $user->getKey(),
            ]);

            return null;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->challengeStore($user)->put($this->challengeKey($user), [
            'code_hash' => Hash::make($code),
            'email_hash' => $known->email_hash,
            'attempts' => 0,
        ], now()->addMinutes(self::CHALLENGE_TTL_MINUTES));

        Log::info('ContributorVerification: possession code issued', [
            'user_id' => $user->getKey(),
        ]);

        return $code;
    }

    /**
     * Verify a possession code and create the contribution proof on
     * success (SPEC 3.2, step 5).
     *
     * @return ContributorProof the freshly created (or already owned) proof
     *
     * @throws ContributorVerificationException on unknown/expired/locked
     *                                          challenge, wrong code, or hash already bound to another
     *                                          account (SPEC 3.4).
     */
    public function verifyEmailCode(User $user, string $code): ContributorProof
    {
        $key = $this->challengeKey($user);
        $store = $this->challengeStore($user);
        $challenge = $store->get($key);

        if (! is_array($challenge) || ! isset($challenge['code_hash'], $challenge['email_hash'])) {
            Log::warning('ContributorVerification: no pending challenge', ['user_id' => $user->getKey()]);

            throw new ContributorVerificationException('Aucun défi en attente pour ce compte.');
        }

        if ((int) ($challenge['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            Log::warning('ContributorVerification: challenge locked after too many attempts', [
                'user_id' => $user->getKey(),
            ]);

            throw new ContributorVerificationException('Trop de tentatives, demandez un nouveau code.');
        }

        if (! Hash::check($code, (string) $challenge['code_hash'])) {
            $challenge['attempts'] = (int) ($challenge['attempts'] ?? 0) + 1;
            $store->put($key, $challenge, now()->addMinutes(self::CHALLENGE_TTL_MINUTES));

            Log::warning('ContributorVerification: wrong code', [
                'user_id' => $user->getKey(),
                'attempts' => $challenge['attempts'],
            ]);

            throw new ContributorVerificationException('Code incorrect.');
        }

        $store->forget($key);

        return $this->createProof($user, ProofMethod::EMAIL, (string) $challenge['email_hash']);
    }

    /**
     * Issue a GPG challenge for the strong level (SPEC 3.2): a random
     * phrase the candidate must sign with the key that signed the commits.
     *
     * Returns [challenge, expiration] or null when the address is unknown
     * to the reference repositories.
     *
     * @return array{challenge: string, expires_at: Carbon}|null
     */
    public function issueGpgChallenge(User $user, string $commitAddress): ?array
    {
        $known = $this->lookup($commitAddress);

        if ($known === null) {
            return null;
        }

        $challenge = 'dolinews-proof:'.Str::random(40);

        $this->challengeStore($user)->put($this->gpgKey($user), [
            'challenge' => $challenge,
            'email_hash' => $known->email_hash,
        ], now()->addMinutes(self::CHALLENGE_TTL_MINUTES));

        return [
            'challenge' => $challenge,
            'expires_at' => now()->addMinutes(self::CHALLENGE_TTL_MINUTES),
        ];
    }

    /**
     * Verify a detached ASCII-armored signature of the outstanding GPG
     * challenge, made with the key whose uid matches the commit address,
     * and create the proof on success (SPEC 3.2, strong level).
     *
     * @throws ContributorVerificationException when gpg is unavailable, no
     *                                          challenge is pending, the signature does not verify, or the
     *                                          signing uid does not match the claimed address.
     */
    public function verifyGpgSignature(User $user, string $publicKey, string $signature): ContributorProof
    {
        $key = $this->gpgKey($user);
        $store = $this->challengeStore($user);
        $challenge = $store->get($key);

        if (! is_array($challenge) || ! isset($challenge['challenge'], $challenge['email_hash'])) {
            throw new ContributorVerificationException('Aucun défi GPG en attente pour ce compte.');
        }

        $result = $this->gpgVerify((string) $challenge['challenge'], $publicKey, $signature, $user);

        if (! $result['verified']) {
            throw new ContributorVerificationException(
                'Signature non vérifiée : '.$result['reason']
            );
        }

        $store->forget($key);

        return $this->createProof($user, ProofMethod::GPG, (string) $challenge['email_hash']);
    }

    /**
     * Manual validation by the moderation team (SPEC 3.3): editors
     * without a public repository, or anonymised redirect addresses for
     * which no email can be delivered.
     *
     * The hash is still recorded and bound, so the uniqueness rule of
     * SPEC 3.4 applies to manually validated contributors as well.
     */
    public function grantManual(User $user, string $commitAddress): ContributorProof
    {
        return $this->createProof(
            $user,
            ProofMethod::MANUAL,
            CommitterEmailHasher::hash($commitAddress)
        );
    }

    /**
     * Revoke a proof: revoked_at is set, the row is kept so the address
     * stays bound and cannot register a fresh account (SPEC 3.4/9.8).
     * Idempotent on an already-revoked proof.
     */
    public function revoke(ContributorProof $proof): ContributorProof
    {
        if ($proof->revoked_at !== null) {
            return $proof;
        }

        $proof->revoked_at = now();
        $proof->save();

        Log::info('ContributorVerification: proof revoked', [
            'proof_id' => $proof->getKey(),
            'user_id' => $proof->user_id,
        ]);

        return $proof;
    }

    /**
     * Create (or return when already owned) the proof for a verified
     * hash, enforcing one account per address (SPEC 3.4).
     *
     * @throws ContributorVerificationException when the hash is already
     *                                          bound to another account.
     */
    private function createProof(User $user, ProofMethod $method, string $emailHash): ContributorProof
    {
        /** @var ContributorProof|null $existing */
        $existing = ContributorProof::query()
            ->where('email_hash', $emailHash)
            ->first();

        if ($existing !== null) {
            if ($existing->user_id === $user->getKey()) {
                return $existing;
            }

            Log::warning('ContributorVerification: hash already bound to another account', [
                'proof_id' => $existing->getKey(),
                'user_id' => $existing->user_id,
            ]);

            throw new ContributorVerificationException(
                'Cette adresse de commit est déjà rattachée à un autre compte.'
            );
        }

        $known = KnownCommitterHash::query()
            ->where('email_hash', $emailHash)
            ->orderByDesc('commit_count')
            ->first();

        // Read before the insert: the notified event is the passage to
        // the contributor class, not a further address on an account
        // that already writes. A revoked proof still counts as a
        // passage already announced.
        $firstProof = ! $user->proofs()->exists();

        $proof = ContributorProof::query()->create([
            'user_id' => $user->getKey(),
            'method' => $method,
            'email_hash' => $emailHash,
            'source_repo' => $known instanceof KnownCommitterHash ? $known->source_repo : 'manual',
            'commit_count' => $known instanceof KnownCommitterHash ? $known->commit_count : 0,
            'verified_at' => now(),
        ]);

        Log::info('ContributorVerification: proof created', [
            'proof_id' => $proof->getKey(),
            'user_id' => $user->getKey(),
            'method' => $method->value,
        ]);

        if ($firstProof && $this->announceQualification) {
            User::query()
                ->superAdmins()
                ->get()
                ->each(fn (User $admin) => $admin->notify(
                    new ContributorQualified($user, $proof),
                ));
        }

        return $proof;
    }

    /**
     * Verify the detached signature against the imported public key in a
     * throwaway keyring, then check the signing key's uid addresses the
     * challenge was issued for (passed as claimedEmailHash: we compare
     * hashes, the clear address never needs storing).
     *
     * @param  string  $challenge  the exact phrase that was signed
     * @param  string  $publicKey  ASCII-armored public key block
     * @param  string  $signature  ASCII-armored detached signature
     * @return array{verified: bool, reason: string}
     */
    private function gpgVerify(
        string $challenge,
        string $publicKey,
        string $signature,
        User $user,
    ): array {
        $gpg = trim((string) shell_exec('command -v gpg'));

        if ($gpg === '') {
            Log::error('ContributorVerification: gpg binary not available on this host');

            return ['verified' => false, 'reason' => 'outil GPG indisponible sur le serveur'];
        }

        $home = (string) realpath(sys_get_temp_dir()).'/dolinews-gpg-'.Str::random(12);

        try {
            $env = ['GNUPGHOME' => $home];
            @mkdir($home, 0700);

            $import = Process::env($env)->timeout(30)->input($publicKey)->run([$gpg, '--import']);

            if (! $import->successful()) {
                Log::warning('ContributorVerification: gpg key import failed', [
                    'user_id' => $user->getKey(),
                    'stderr' => trim($import->errorOutput()),
                ]);

                return ['verified' => false, 'reason' => 'clef publique illisible'];
            }

            // List uids of the imported key, hashed for comparison.
            $uids = Process::env($env)->timeout(30)->run([$gpg, '--list-keys', '--with-colons']);

            $uidEmails = [];
            foreach (explode("\n", $uids->output()) as $line) {
                if (str_starts_with($line, 'uid:')) {
                    $fields = explode(':', $line);
                    $uid = $fields[9] ?? '';
                    // Extract the address from "Name <address>".
                    if (preg_match('/<([^>]+)>/', $uid, $m) === 1) {
                        $uidEmails[] = $m[1];
                    }
                }
            }

            $sigFile = $home.'/signature.asc';
            $dataFile = $home.'/challenge.txt';
            file_put_contents($sigFile, $signature);
            file_put_contents($dataFile, $challenge);

            $verify = Process::env($env)->timeout(30)->run([
                $gpg, '--verify', $sigFile, $dataFile,
            ]);

            if (! $verify->successful()) {
                Log::info('ContributorVerification: gpg signature rejected', [
                    'user_id' => $user->getKey(),
                ]);

                return ['verified' => false, 'reason' => 'signature invalide ou ne couvrant pas le défi'];
            }

            // The signature must come from the key carrying the claimed
            // commit address among its uids.
            foreach ($uidEmails as $uidEmail) {
                try {
                    if (CommitterEmailHasher::hash($uidEmail) === $this->pendingEmailHash($user)) {
                        return ['verified' => true, 'reason' => ''];
                    }
                } catch (\RuntimeException) {
                    return ['verified' => false, 'reason' => 'poivre non configuré'];
                }
            }

            return ['verified' => false, 'reason' => 'la clef signataire ne porte pas l\'adresse de commit annoncée'];
        } finally {
            $this->removeKeyring($home);
        }
    }

    /**
     * Wipe a throwaway GNUPGHOME, whatever gpg left in it.
     *
     * rmdir alone only ever worked on an empty directory, and gpg fills
     * this one with keyrings, a trustdb and its own sockets: every
     * verification left a 0700 dolinews-gpg-* directory behind, holding
     * the public key that was checked.
     */
    private function removeKeyring(string $home): void
    {
        // Never walk out of the temp directory, whatever $home holds.
        $temp = (string) realpath(sys_get_temp_dir());
        $real = (string) realpath($home);

        if ($real === '' || $temp === '' || ! str_starts_with($real, $temp.'/')) {
            Log::warning('ContributorVerification: refusing to wipe a keyring outside the temp directory', [
                'path' => $home,
            ]);

            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if (! $entry instanceof \SplFileInfo) {
                continue;
            }

            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        if (! @rmdir($real)) {
            Log::warning('ContributorVerification: temporary keyring left on disk', [
                'path' => $real,
            ]);
        }
    }

    /**
     * Email hash of the currently pending GPG challenge, when any.
     */
    private function pendingEmailHash(User $user): ?string
    {
        $challenge = $this->challengeStore($user)->get($this->gpgKey($user));

        if (is_array($challenge) && isset($challenge['email_hash'])) {
            return (string) $challenge['email_hash'];
        }

        return null;
    }

    /**
     * Challenge storage: short-lived, bounded by TTL, per user.
     */
    private function challengeStore(User $user): CacheRepository
    {
        return cache()->store();
    }

    private function challengeKey(User $user): string
    {
        return 'dolinews:proof-code:'.$user->getKey();
    }

    private function gpgKey(User $user): string
    {
        return 'dolinews:proof-gpg:'.$user->getKey();
    }
}

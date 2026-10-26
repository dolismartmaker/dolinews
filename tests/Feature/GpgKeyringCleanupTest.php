<?php

declare(strict_types=1);

use App\Domain\Dolinews\Contributors\ContributorVerificationException;
use App\Domain\Dolinews\Contributors\ContributorVerificationService;
use App\Domain\Dolinews\Models\KnownCommitterHash;
use App\Domain\Dolinews\Models\KnownCommitterKey;
use App\Domain\Dolinews\Support\CommitterEmailHasher;
use App\Models\User;

/**
 * The throwaway keyring is wiped, whatever gpg left in it (revue F7).
 *
 * gpg initialises GNUPGHOME on the very first call, import failure
 * included: a plain rmdir never emptied it, so every verification left
 * a 0700 directory behind on the server.
 */
function temporaryKeyrings(): array
{
    return glob(sys_get_temp_dir().'/dolinews-gpg-*') ?: [];
}

it('leaves no keyring behind when the key does not even import', function (): void {
    if (trim((string) shell_exec('command -v gpg')) === '') {
        $this->markTestSkipped('gpg absent de cette machine.');
    }

    $before = temporaryKeyrings();

    $user = User::factory()->create();

    KnownCommitterHash::query()->create([
        'email_hash' => CommitterEmailHasher::hash('contributeur@exemple.test'),
        'source_repo' => '/repos/dolibarr',
        'commit_count' => 12,
    ]);

    // A signing key is known for the address, so a challenge is issued
    // and the import really runs.
    KnownCommitterKey::query()->create([
        'email_hash' => CommitterEmailHasher::hash('contributeur@exemple.test'),
        'source_repo' => '/repos/dolibarr',
        'key_fingerprint' => str_repeat('AB', 20),
    ]);

    $service = app(ContributorVerificationService::class);
    expect($service->issueGpgChallenge($user, 'contributeur@exemple.test'))->not->toBeNull();

    try {
        $service->verifyGpgSignature($user, 'pas une clef', 'pas une signature');
    } catch (ContributorVerificationException) {
        // Expected: what matters is what is left on disk afterwards.
    }

    expect(temporaryKeyrings())->toBe($before);
});

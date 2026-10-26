<?php

declare(strict_types=1);

use App\Domain\Dolinews\Contributors\CommitterHarvester;
use App\Domain\Dolinews\Contributors\ContributorVerificationException;
use App\Domain\Dolinews\Contributors\ContributorVerificationService;
use App\Domain\Dolinews\Enums\ProofMethod;
use App\Domain\Dolinews\Models\ContributorProof;
use App\Domain\Dolinews\Models\KnownCommitterKey;
use App\Domain\Dolinews\Support\CommitterEmailHasher;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Strong-level proof (SPEC 3.2): the challenge must be signed by a key
 * observed signing commits of the address, never merely by a key whose
 * uid carries it.
 *
 * Every keyring here is a throwaway GNUPGHOME under the temp directory:
 * the keyring of whoever runs the suite is never touched.
 */
function gpgAvailable(): bool
{
    return trim((string) shell_exec('command -v gpg')) !== '';
}

/**
 * @param  array<string, string>  $env
 * @param  list<string>  $args
 */
function gpgRun(array $env, array $args, string $input = ''): string
{
    $result = Process::env($env)->timeout(60)->input($input)->run(array_merge(['gpg', '--batch'], $args));

    if (! $result->successful()) {
        throw new RuntimeException('gpg failed: '.trim($result->errorOutput()));
    }

    return $result->output();
}

/**
 * Create a throwaway keyring holding one fresh signing key.
 *
 * @return array{env: array<string, string>, home: string, fingerprint: string}
 */
function gpgIdentity(string $root, string $name, string $uid): array
{
    $home = $root.'/'.$name;
    mkdir($home, 0700, true);
    $env = ['GNUPGHOME' => $home, 'LC_ALL' => 'C'];

    gpgRun($env, ['--passphrase', '', '--pinentry-mode', 'loopback', '--quick-gen-key', $uid, 'ed25519', 'sign', 'never']);

    $colons = gpgRun($env, ['--with-colons', '--list-keys']);
    preg_match('/^fpr:+([0-9A-F]+):/m', $colons, $m);

    return ['env' => $env, 'home' => $home, 'fingerprint' => $m[1]];
}

/**
 * @param  array{env: array<string, string>, home: string, fingerprint: string}  $identity
 * @return array{public_key: string, signature: string}
 */
function gpgSign(array $identity, string $challenge): array
{
    return [
        'public_key' => gpgRun($identity['env'], ['--armor', '--export', $identity['fingerprint']]),
        'signature' => gpgRun($identity['env'], [
            '--armor', '--detach-sign', '--local-user', $identity['fingerprint'],
            '--pinentry-mode', 'loopback', '--passphrase', '',
        ], $challenge),
    ];
}

/**
 * @param  array<string, string>  $env
 * @param  list<string>  $args
 */
function gpgRepoGit(string $repo, array $env, array $args): void
{
    $result = Process::path($repo)->env($env)->timeout(60)->run(array_merge(['git'], $args));

    if (! $result->successful()) {
        throw new RuntimeException('git failed: '.trim($result->errorOutput()));
    }
}

beforeEach(function (): void {
    if (! gpgAvailable()) {
        $this->markTestSkipped('gpg absent de cette machine.');
    }

    config()->set('dolinews.verification.pepper', 'gpg-pepper');

    $this->root = sys_get_temp_dir().'/dolinews-gpgtest-'.uniqid();
    mkdir($this->root, 0700, true);

    $this->contributor = gpgIdentity($this->root, 'contributor', 'Vrai <dev@example.com>');

    // One signed commit by dev@example.com, one unsigned commit by
    // plain@example.com.
    $this->repo = $this->root.'/repo';
    mkdir($this->repo);
    $git = $this->contributor['env'];
    gpgRepoGit($this->repo, $git, ['init', '-q']);
    gpgRepoGit($this->repo, $git, [
        '-c', 'user.email=dev@example.com', '-c', 'user.name=Vrai',
        '-c', 'user.signingkey='.$this->contributor['fingerprint'], '-c', 'gpg.program=gpg',
        'commit', '-q', '-S', '--allow-empty', '-m', 'signed',
    ]);
    gpgRepoGit($this->repo, $git, [
        '-c', 'user.email=plain@example.com', '-c', 'user.name=Plain',
        '-c', 'commit.gpgsign=false',
        'commit', '-q', '--allow-empty', '-m', 'unsigned',
    ]);

    (new CommitterHarvester)->harvestRepository($this->repo);
});

afterEach(function (): void {
    if (! isset($this->root)) {
        return;
    }

    foreach (glob($this->root.'/*', GLOB_ONLYDIR) ?: [] as $home) {
        Process::env(['GNUPGHOME' => $home])->run(['gpgconf', '--kill', 'all']);
    }

    File::deleteDirectory($this->root);
});

it('records the fingerprint of the key that signed the commits of an address', function (): void {
    $keys = KnownCommitterKey::query()
        ->where('email_hash', CommitterEmailHasher::hash('dev@example.com'))
        ->pluck('key_fingerprint')
        ->all();

    expect($keys)->toBe([$this->contributor['fingerprint']])
        ->and(KnownCommitterKey::query()->where('email_hash', CommitterEmailHasher::hash('plain@example.com'))->exists())
        ->toBeFalse();
});

it('qualifies a challenge signed by the key observed on the commits', function (): void {
    $user = User::factory()->create();
    $service = app(ContributorVerificationService::class);

    $challenge = $service->issueGpgChallenge($user, 'dev@example.com');
    expect($challenge)->not->toBeNull();

    $signed = gpgSign($this->contributor, $challenge['challenge']);
    $proof = $service->verifyGpgSignature($user, $signed['public_key'], $signed['signature']);

    expect($proof->method)->toBe(ProofMethod::GPG)
        ->and($proof->user_id)->toBe($user->getKey())
        ->and($proof->email_hash)->toBe(CommitterEmailHasher::hash('dev@example.com'));
});

it('refuses a key whose uid carries the address but which never signed its commits', function (): void {
    $impostor = gpgIdentity($this->root, 'impostor', 'Faux <dev@example.com>');
    $user = User::factory()->create();
    $service = app(ContributorVerificationService::class);

    $challenge = $service->issueGpgChallenge($user, 'dev@example.com');
    expect($challenge)->not->toBeNull();

    $signed = gpgSign($impostor, $challenge['challenge']);

    expect(fn () => $service->verifyGpgSignature($user, $signed['public_key'], $signed['signature']))
        ->toThrow(ContributorVerificationException::class, 'la clef signataire n\'est pas celle qui signe les commits de cette adresse');

    expect(ContributorProof::query()->count())->toBe(0);
});

it('refuses a good signature of another phrase than the challenge', function (): void {
    $user = User::factory()->create();
    $service = app(ContributorVerificationService::class);

    $service->issueGpgChallenge($user, 'dev@example.com');
    $signed = gpgSign($this->contributor, 'dolinews-proof:une-autre-phrase');

    expect(fn () => $service->verifyGpgSignature($user, $signed['public_key'], $signed['signature']))
        ->toThrow(ContributorVerificationException::class);

    expect(ContributorProof::query()->count())->toBe(0);
});

it('offers no GPG path to an address without signed commits', function (): void {
    $user = User::factory()->create();

    expect(app(ContributorVerificationService::class)->issueGpgChallenge($user, 'plain@example.com'))->toBeNull();
});

it('points an address without signed commits to manual validation', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('account.contribute'))
        ->post(route('account.contribute.start'), [
            'commit_address' => 'plain@example.com',
            'method' => 'gpg',
        ])
        ->assertRedirect(route('account.contribute'))
        ->assertSessionHasErrors('commit_address');

    expect(session('errors')->first('commit_address'))->toContain('validation manuelle');
});

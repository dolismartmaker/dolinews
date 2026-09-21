<?php

declare(strict_types=1);

use App\Domain\Dolinews\Contributors\CommitterHarvester;
use App\Domain\Dolinews\Contributors\ContributorVerificationException;
use App\Domain\Dolinews\Contributors\ContributorVerificationService;
use App\Domain\Dolinews\Models\KnownCommitterHash;
use App\Domain\Dolinews\Support\CommitterEmailHasher;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;

/**
 * Contributor qualification (SPEC 3): peppered lookup, one-time code,
 * one account per address.
 */
function buildReferenceRepo(string $path, array $authors): void
{
    mkdir($path, 0777, true);

    runGit($path, ['init', '-q']);

    foreach ([
        ['config', 'user.email', 'root@example.com'],
        ['config', 'user.name', 'Root'],
    ] as $init) {
        runGit($path, $init);
    }

    $i = 0;
    foreach ($authors as $email => $count) {
        for ($j = 0; $j < $count; $j++) {
            file_put_contents($path.'/file-'.$i.'.txt', "content $i\n");
            runGit($path, ['add', '.']);
            runGit($path, ['-c', 'user.email='.$email, '-c', 'user.name=Author', 'commit', '-q', '-m', "c$i"]);
            $i++;
        }
    }
}

function runGit(string $path, array $args): void
{
    $result = Process::path($path)->timeout(30)->run(
        array_merge(['git'], $args)
    );

    if (! $result->successful()) {
        throw new RuntimeException('git failed: '.trim($result->errorOutput()));
    }
}

it('harvests commit authors into peppered hashes', function (): void {
    config()->set('dolinews.verification.pepper', 'harvest-pepper');

    $repo = sys_get_temp_dir().'/dolinews-harvest-'.uniqid();
    buildReferenceRepo($repo, ['dev@example.com' => 3, 'other@example.com' => 1]);

    try {
        $new = (new CommitterHarvester)->harvestRepository($repo);

        expect($new)->toBe(2);

        $hash = CommitterEmailHasher::hash('dev@example.com');

        $row = KnownCommitterHash::query()
            ->where('email_hash', $hash)
            ->where('source_repo', $repo)
            ->first();

        expect($row)->not->toBeNull()
            ->and($row->commit_count)->toBe(3);

        // The clear address is never stored.
        $this->assertDatabaseMissing('known_committer_hashes', [
            'email_hash' => 'dev@example.com',
        ]);
    } finally {
        File::deleteDirectory($repo);
    }
});

it('looks an address up in the harvested index', function (): void {
    $service = new ContributorVerificationService;

    KnownCommitterHash::query()->create([
        'email_hash' => CommitterEmailHasher::hash('dev@example.com'),
        'source_repo' => '/repos/dolibarr',
        'commit_count' => 42,
    ]);

    expect($service->lookup('dev@example.com')?->commit_count)->toBe(42)
        ->and($service->lookup('unknown@example.com'))->toBeNull();
});

it('issues a one-time code and creates a proof on success', function (): void {
    Mail::fake();

    $user = User::factory()->create();
    $service = new ContributorVerificationService;

    KnownCommitterHash::query()->create([
        'email_hash' => CommitterEmailHasher::hash('dev@example.com'),
        'source_repo' => '/repos/dolibarr',
        'commit_count' => 10,
    ]);

    $code = $service->issueEmailChallenge($user, 'dev@example.com');

    expect($code)->toMatch('/^\d{6}$/');

    // A wrong code is refused and counted.
    try {
        $service->verifyEmailCode($user, '000000');
        $this->fail('wrong code accepted');
    } catch (ContributorVerificationException) {
    }

    $proof = $service->verifyEmailCode($user, $code);

    expect($proof->user_id)->toBe($user->getKey())
        ->and($proof->commit_count)->toBe(10)
        ->and($proof->revoked_at)->toBeNull()
        ->and($user->isContributor())->toBeTrue();
});

it('binds one address hash to a single account only', function (): void {
    $service = new ContributorVerificationService;

    $first = User::factory()->create();
    $second = User::factory()->create();

    $service->grantManual($first, 'dev@example.com');

    $service->grantManual($second, 'dev@example.com');
})->throws(ContributorVerificationException::class);

it('keeps a revoked proof bound so the address cannot re-register', function (): void {
    $service = new ContributorVerificationService;

    $user = User::factory()->create();
    $proof = $service->grantManual($user, 'dev@example.com');

    $service->revoke($proof);

    expect($proof->refresh()->revoked_at)->not->toBeNull()
        ->and($user->refresh()->isContributor())->toBeFalse();

    // The row survives: the uniqueness of SPEC 3.4 keeps holding.
    $this->assertDatabaseHas('contributor_proofs', ['email_hash' => $proof->email_hash]);
});

it('grants manual validation for editors without a public repository', function (): void {
    $service = new ContributorVerificationService;

    $user = User::factory()->create();
    $proof = $service->grantManual($user, 'closed@example.com');

    expect($proof->method->value)->toBe('manual')
        ->and($user->isContributor())->toBeTrue();
});

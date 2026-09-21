<?php

declare(strict_types=1);

use App\Domain\Dolinews\Contributors\CommitterCsvImporter;
use App\Domain\Dolinews\Contributors\CommitterHarvester;
use App\Domain\Dolinews\Contributors\ContributorVerificationException;
use App\Domain\Dolinews\Contributors\ContributorVerificationService;
use App\Domain\Dolinews\Models\KnownCommitterHash;
use App\Domain\Dolinews\Support\CommitterEmailHasher;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\URL;

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

/**
 * Address list import (SPEC 3.2): same index, same hashes as the git
 * harvest, only the acquisition channel differs.
 */
function writeAddressList(array $lines): string
{
    $path = sys_get_temp_dir().'/dolinews-committers-'.uniqid().'.csv';

    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}

it('imports an address list into the peppered index', function (): void {
    // Real-world noise: a header row, a stray cell, blank lines,
    // trailing tabs, a duplicate, an invalid address.
    $path = writeAddressList([
        'email,name,commits',
        '',
        '=',
        "dev@example.com\t",
        'dev@example.com,Dev,17',
        '"other@example.com";Other;3',
        'not-an-address',
        '  MiXeD@Example.COM  ',
    ]);

    try {
        $stats = (new CommitterCsvImporter)->import($path, 'csv:test');

        expect($stats['addresses'])->toBe(3)
            ->and($stats['stored'])->toBe(3)
            ->and($stats['skipped'])->toBe(4);

        $service = new ContributorVerificationService;

        // Highest count wins across duplicate lines, and a countless
        // line still means one observed commit.
        expect($service->lookup('dev@example.com')?->commit_count)->toBe(17)
            ->and($service->lookup('other@example.com')?->commit_count)->toBe(3)
            ->and($service->lookup('mixed@example.com')?->commit_count)->toBe(1)
            ->and($service->lookup('not-an-address'))->toBeNull();

        // The clear address is never stored.
        $this->assertDatabaseMissing('known_committer_hashes', [
            'email_hash' => 'dev@example.com',
        ]);
    } finally {
        @unlink($path);
    }
});

it('refreshes counts on re-import instead of duplicating rows', function (): void {
    $first = writeAddressList(['dev@example.com,2']);
    $second = writeAddressList(['dev@example.com,9']);

    try {
        (new CommitterCsvImporter)->import($first, 'csv:test');
        $stats = (new CommitterCsvImporter)->import($second, 'csv:test');

        expect($stats['stored'])->toBe(0)
            ->and($stats['updated'])->toBe(1)
            ->and(KnownCommitterHash::query()->count())->toBe(1)
            ->and((new ContributorVerificationService)->lookup('dev@example.com')?->commit_count)->toBe(9);
    } finally {
        @unlink($first);
        @unlink($second);
    }
});

it('refuses an unreadable file', function (): void {
    (new CommitterCsvImporter)->import('/nonexistent/committers.csv', 'csv:test');
})->throws(RuntimeException::class);

/**
 * Automatic qualification (SPEC 3.2): the registration link already
 * proved possession of the account address.
 */
it('qualifies a verified account whose address is a known commit address', function (): void {
    $user = User::factory()->create(['email' => 'dev@example.com']);

    KnownCommitterHash::query()->create([
        'email_hash' => CommitterEmailHasher::hash('dev@example.com'),
        'source_repo' => 'csv:dolibarr',
        'commit_count' => 12,
    ]);

    $proof = (new ContributorVerificationService)->autoLinkFromAccountAddress($user);

    expect($proof)->not->toBeNull()
        ->and($proof->method->value)->toBe('email')
        ->and($proof->commit_count)->toBe(12)
        ->and($proof->source_repo)->toBe('csv:dolibarr')
        ->and($user->isContributor())->toBeTrue();
});

it('never qualifies an account whose address is not verified', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'dev@example.com']);

    KnownCommitterHash::query()->create([
        'email_hash' => CommitterEmailHasher::hash('dev@example.com'),
        'source_repo' => 'csv:dolibarr',
        'commit_count' => 12,
    ]);

    expect((new ContributorVerificationService)->autoLinkFromAccountAddress($user))->toBeNull()
        ->and($user->isContributor())->toBeFalse();
});

it('never qualifies a suspended account', function (): void {
    $user = User::factory()->suspended()->create(['email' => 'dev@example.com']);

    KnownCommitterHash::query()->create([
        'email_hash' => CommitterEmailHasher::hash('dev@example.com'),
        'source_repo' => 'csv:dolibarr',
        'commit_count' => 12,
    ]);

    expect((new ContributorVerificationService)->autoLinkFromAccountAddress($user))->toBeNull()
        ->and($user->isContributor())->toBeFalse();
});

it('leaves an unknown address alone', function (): void {
    $user = User::factory()->create(['email' => 'reader@example.com']);

    expect((new ContributorVerificationService)->autoLinkFromAccountAddress($user))->toBeNull()
        ->and($user->isContributor())->toBeFalse();
});

it('refuses to auto-qualify a hash already bound to another account', function (): void {
    $service = new ContributorVerificationService;

    $owner = User::factory()->create();
    $service->grantManual($owner, 'dev@example.com');

    KnownCommitterHash::query()->create([
        'email_hash' => CommitterEmailHasher::hash('dev@example.com'),
        'source_repo' => 'csv:dolibarr',
        'commit_count' => 12,
    ]);

    $impostor = User::factory()->create(['email' => 'dev@example.com']);

    expect($service->autoLinkFromAccountAddress($impostor))->toBeNull()
        ->and($impostor->isContributor())->toBeFalse();
});

it('does not resurrect a revoked proof', function (): void {
    $service = new ContributorVerificationService;

    $user = User::factory()->create(['email' => 'dev@example.com']);
    $service->revoke($service->grantManual($user, 'dev@example.com'));

    KnownCommitterHash::query()->create([
        'email_hash' => CommitterEmailHasher::hash('dev@example.com'),
        'source_repo' => 'csv:dolibarr',
        'commit_count' => 12,
    ]);

    expect($service->autoLinkFromAccountAddress($user))->toBeNull()
        ->and($user->refresh()->isContributor())->toBeFalse();
});

it('qualifies the account when the verification link is consumed', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'dev@example.com']);

    KnownCommitterHash::query()->create([
        'email_hash' => CommitterEmailHasher::hash('dev@example.com'),
        'source_repo' => 'csv:dolibarr',
        'commit_count' => 4,
    ]);

    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->actingAs($user)->get($url)->assertRedirect(route('account.contribute'));

    expect($user->refresh()->hasVerifiedEmail())->toBeTrue()
        ->and($user->isContributor())->toBeTrue();
});

it('catches up already verified accounts after an import', function (): void {
    $known = User::factory()->create(['email' => 'dev@example.com']);
    $unknown = User::factory()->create(['email' => 'reader@example.com']);
    $pending = User::factory()->unverified()->create(['email' => 'late@example.com']);

    $path = writeAddressList(['dev@example.com,5', 'late@example.com,2']);

    try {
        $this->artisan('dolinews:import-committers', [
            'file' => $path,
            '--source' => 'csv:dolibarr',
            '--link-accounts' => true,
        ])->assertSuccessful();
    } finally {
        @unlink($path);
    }

    expect($known->isContributor())->toBeTrue()
        ->and($unknown->isContributor())->toBeFalse()
        ->and($pending->isContributor())->toBeFalse();
});

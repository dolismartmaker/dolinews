<?php

declare(strict_types=1);

use App\Domain\Dolinews\Support\CommitterEmailHasher;

it('normalises commit addresses for hashing', function (): void {
    expect(CommitterEmailHasher::normalise('  Dev@Example.COM '))->toBe('dev@example.com');
});

it('hashes deterministically with the configured pepper', function (): void {
    config()->set('dolinews.verification.pepper', 'unit-test-pepper');

    $one = CommitterEmailHasher::hash('dev@example.com');
    $two = CommitterEmailHasher::hash('DEV@example.com');

    expect($one)->toBe($two)
        ->and($one)->toMatch('/^[0-9a-f]{64}$/')
        // Distinct from the unpeppered digest: the pepper must matter.
        ->and($one)->not->toBe(hash('sha256', 'dev@example.com'));
});

it('refuses to hash when the pepper is not configured', function (): void {
    config()->set('dolinews.verification.pepper', '');

    CommitterEmailHasher::hash('dev@example.com');
})->throws(RuntimeException::class);

it('detects anonymised forge redirects', function (): void {
    expect(CommitterEmailHasher::isAnonymisedRedirect('1234567+user@users.noreply.github.com'))->toBeTrue()
        ->and(CommitterEmailHasher::isAnonymisedRedirect('dev@example.com'))->toBeFalse();
});

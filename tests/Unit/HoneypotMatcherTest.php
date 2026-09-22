<?php

declare(strict_types=1);

use App\Support\HoneypotMatcher;

it('matches random-named php probes by impossible extension', function (): void {
    config()->set('honeypot.enabled', true);

    $hit = HoneypotMatcher::match('/verox.php');

    expect($hit)->not->toBeNull()
        ->and($hit['level'])->toBe('probable')
        ->and($hit['reason'])->toBe('impossible-extension:php');
});

it('matches secret hunting instantly', function (): void {
    expect(HoneypotMatcher::match('/.env')['level'])->toBe('instant')
        ->and(HoneypotMatcher::match('/.git/config')['level'])->toBe('instant');
});

it('keeps directory prefixes from spilling onto longer names', function (): void {
    // wp-admin/ must take wp-admin/install.php, never wp-administration:
    // the isDirectory flag is read BEFORE normalisation (guide pitfall).
    expect(HoneypotMatcher::match('/wp-admin/install.php'))->not->toBeNull()
        ->and(HoneypotMatcher::match('/wp-administration'))->toBeNull();
});

it('matches probe names regardless of case', function (): void {
    expect(HoneypotMatcher::match('/FWAZ.PHP'))->not->toBeNull()
        ->and(HoneypotMatcher::match('/.ENV'))->not->toBeNull();
});

it('ignores the paths the service legitimately serves', function (string $path): void {
    expect(HoneypotMatcher::match($path))->toBeNull();
})->with([
    '/feeds.xml',
    '/feeds.json',
    '/robots.txt',
    '/fr/donnees',
    '/api/v1/articles',
    '/',
]);

it('lets the controller frontal through', function (): void {
    expect(HoneypotMatcher::match('/index.php'))->toBeNull();
});

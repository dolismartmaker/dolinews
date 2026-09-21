<?php

declare(strict_types=1);

use App\Domain\Dolinews\Projects\OutboundUrlGuard;

it('refuses the ranges a server-side probe must never reach', function (string $address): void {
    expect((new OutboundUrlGuard)->isPublicAddress($address))->toBeFalse();
})->with([
    '127.0.0.1',
    '127.13.37.1',
    '0.0.0.0',
    '10.1.2.3',
    '172.16.9.9',
    '192.168.1.1',
    '100.64.0.1',
    // The cloud metadata endpoint, the reason this guard exists.
    '169.254.169.254',
    '198.18.0.1',
    '224.0.0.1',
    '255.255.255.255',
    '::1',
    'fe80::1',
    'fd00::1',
    'ff02::1',
    // A v4 loopback worn as a v6 address.
    '::ffff:127.0.0.1',
    // Same address behind a 6to4 and a NAT64 prefix.
    '2002:7f00:1::',
    '64:ff9b::7f00:1',
]);

it('accepts ordinary public addresses', function (string $address): void {
    expect((new OutboundUrlGuard)->isPublicAddress($address))->toBeTrue();
})->with([
    '93.184.216.34',
    '8.8.8.8',
    '2606:2800:220:1:248:1893:25c8:1946',
]);

it('refuses anything that is not an address at all', function (): void {
    $guard = new OutboundUrlGuard;

    expect($guard->isPublicAddress('example.com'))->toBeFalse()
        ->and($guard->isPublicAddress(''))->toBeFalse()
        ->and($guard->isPublicAddress('999.1.1.1'))->toBeFalse();
});

it('refuses a literal private target before any lookup', function (string $url): void {
    $result = (new OutboundUrlGuard)->resolve($url);

    expect($result['ok'])->toBeFalse();
})->with([
    'http://169.254.169.254/latest/meta-data/',
    'http://127.0.0.1/',
    'http://[::1]/',
    'http://10.0.0.5/status',
]);

it('refuses schemes and ports outside plain web traffic', function (string $url, string $fragment): void {
    $result = (new OutboundUrlGuard)->resolve($url);

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toContain($fragment);
})->with([
    ['ftp://93.184.216.34/pub', 'schémas'],
    ['gopher://93.184.216.34/', 'schémas'],
    ['http://93.184.216.34:9000/', 'ports'],
    ['https://93.184.216.34:6379/', 'ports'],
    ['https://', 'illisible'],
]);

it('hands back the vetted address so the caller can pin it', function (): void {
    $result = (new OutboundUrlGuard)->resolve('https://93.184.216.34/page');

    expect($result['ok'])->toBeTrue()
        ->and($result['ip'])->toBe('93.184.216.34')
        ->and($result['host'])->toBe('93.184.216.34')
        ->and($result['port'])->toBe(443);
});

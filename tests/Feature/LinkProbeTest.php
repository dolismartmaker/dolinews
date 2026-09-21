<?php

declare(strict_types=1);

use App\Domain\Dolinews\Projects\LinkProbe;
use Illuminate\Support\Facades\Http;

it('marks a link broken when its address may not be contacted', function (): void {
    Http::fake();

    $result = app(LinkProbe::class)->check('http://169.254.169.254/latest/meta-data/', 5);

    expect($result['broken'])->toBeTrue();

    // The point of the guard: the request is never sent at all.
    Http::assertNothingSent();
});

it('stops at a redirect leading to a private address', function (): void {
    Http::fake([
        'https://93.184.216.34/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/']),
    ]);

    $result = app(LinkProbe::class)->check('https://93.184.216.34/page', 5);

    expect($result['broken'])->toBeTrue();

    // One hop was probed, the second was refused before leaving.
    Http::assertSentCount(1);
});

it('follows a redirect to another public address', function (): void {
    Http::fake([
        'https://93.184.216.34/*' => Http::response('', 301, ['Location' => 'https://8.8.8.8/moved']),
        'https://8.8.8.8/*' => Http::response('', 200),
    ]);

    expect(app(LinkProbe::class)->check('https://93.184.216.34/page', 5)['broken'])->toBeFalse();
});

it('gives up rather than chase an endless redirect chain', function (): void {
    Http::fake([
        'https://93.184.216.34/*' => Http::response('', 302, ['Location' => '/again']),
    ]);

    $result = app(LinkProbe::class)->check('https://93.184.216.34/page', 5);

    expect($result['broken'])->toBeTrue()
        ->and($result['reason'])->toBe('trop de redirections');
});

it('falls back to GET when the peer rejects HEAD', function (): void {
    Http::fake([
        'https://93.184.216.34/*' => Http::sequence()
            ->push('', 405)
            ->push('', 200),
    ]);

    expect(app(LinkProbe::class)->check('https://93.184.216.34/page', 5)['broken'])->toBeFalse();

    Http::assertSentCount(2);
});

it('reports an ordinary dead page as broken', function (): void {
    Http::fake([
        'https://93.184.216.34/*' => Http::response('', 404),
    ]);

    $result = app(LinkProbe::class)->check('https://93.184.216.34/gone', 5);

    expect($result['broken'])->toBeTrue()
        ->and($result['reason'])->toBe('statut 404');
});

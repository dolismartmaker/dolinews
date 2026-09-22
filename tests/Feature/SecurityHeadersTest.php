<?php

declare(strict_types=1);

use App\Models\User;

/**
 * Browser-side defence in depth (revue M5).
 */
it('carries the fixed headers on every surface', function (string $path): void {
    $response = $this->get($path);

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'self'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'");
})->with([
    '/',
    '/login',
    '/feeds.xml',
    '/api/v1/articles',
]);

it('keeps the public surface free of inline and evaluated script', function (): void {
    $policy = (string) $this->get('/fr')->headers->get('Content-Security-Policy');

    expect($policy)->toContain("script-src 'self'")
        ->and($policy)->not->toContain('unsafe-inline')
        ->and($policy)->not->toContain('unsafe-eval');
});

it('relaxes script only where Livewire runs', function (): void {
    $admin = User::factory()->superAdmin()->create();

    $policy = (string) $this->actingAs($admin)->get('/admin')
        ->headers->get('Content-Security-Policy');

    expect($policy)->toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval'");
});

it('announces HSTS only in production over https', function (): void {
    $this->get('/fr')->assertHeaderMissing('Strict-Transport-Security');
});

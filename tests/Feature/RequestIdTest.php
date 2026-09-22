<?php

declare(strict_types=1);

/**
 * Correlation id (revue F8): a client value is carried only when it
 * looks like an id, otherwise it is regenerated.
 */
it('carries a well-formed client id', function (string $requestId): void {
    $this->withHeader('X-Request-Id', $requestId)
        ->get('/fr')
        ->assertHeader('X-Request-Id', $requestId);
})->with([
    '0199f0b7-8e0a-7c3e-9a31-9f4c2c6f5a10',
    'ci-run-4821',
    'deploy.2026_09_21',
]);

it('regenerates an id it would not want in its logs', function (string $requestId): void {
    $carried = (string) $this->withHeader('X-Request-Id', $requestId)
        ->get('/fr')
        ->headers->get('X-Request-Id');

    expect($carried)->not->toBe($requestId)
        ->and($carried)->toMatch('/^[0-9a-f-]{36}$/');
})->with([
    'valeur avec espaces',
    'injection: <script>',
    'clef=valeur&autre=chose',
    str_repeat('a', 65),
    '',
]);

it('mints one when the client sends none', function (): void {
    expect((string) $this->get('/fr')->headers->get('X-Request-Id'))
        ->toMatch('/^[0-9a-f-]{36}$/');
});

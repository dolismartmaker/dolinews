<?php

declare(strict_types=1);

use App\Core\Enums\ApiErrorCode;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The API while the service is down for an update.
 *
 * A publishing tool used to receive the HTML maintenance page and stop,
 * where it only had to wait: the error envelope is the contract of every
 * refusal, maintenance included, with the Retry-After telling how long.
 *
 * The prerendered case is tested through the closure public/index.php
 * calls, on a down file of its own: writing the real one would put every
 * other test of a parallel run into maintenance.
 */
function maintenanceAnswer(array $down, string $uri, array $cookies = []): ?array
{
    $file = tempnam(sys_get_temp_dir(), 'down');
    file_put_contents($file, json_encode($down));

    try {
        return (require base_path('bootstrap/maintenance-api.php'))($file, $uri, $cookies);
    } finally {
        unlink($file);
    }
}

it('answers the API with the envelope while the page is prerendered', function (): void {
    $answer = maintenanceAnswer(['template' => '<html>page</html>', 'retry' => 20], '/api/v1/profile?x=1');

    expect($answer['status'])->toBe(503)
        ->and($answer['headers']['Retry-After'])->toBe('20')
        ->and($answer['headers']['Content-Type'])->toBe('application/json')
        ->and(json_decode($answer['body'], true))->toBe([
            'error' => 'MAINTENANCE',
            'message' => ApiErrorCode::MAINTENANCE->message(),
        ]);

    expect(maintenanceAnswer(['template' => 'x'], '/api/mcp')['status'])->toBe(503);
});

it('leaves the site to its prerendered page', function (): void {
    expect(maintenanceAnswer(['template' => 'x'], '/fr/articles/1'))->toBeNull()
        ->and(maintenanceAnswer(['template' => 'x'], '/apiculture'))->toBeNull();
});

it('leaves the framework to answer when there is no prerendered page', function (): void {
    expect(maintenanceAnswer(['retry' => 20], '/api/v1/articles'))->toBeNull()
        ->and((require base_path('bootstrap/maintenance-api.php'))('/nonexistent/down', '/api/v1/articles', []))->toBeNull();
});

it('honours the paths the maintenance excepts and a valid bypass cookie', function (): void {
    expect(maintenanceAnswer(['template' => 'x', 'except' => ['api/v1/*']], '/api/v1/articles'))->toBeNull();

    $expires = time() + 600;
    $cookie = base64_encode((string) json_encode([
        'expires_at' => $expires,
        'mac' => hash_hmac('sha256', (string) $expires, 'secret-de-test'),
    ]));

    expect(maintenanceAnswer(['template' => 'x', 'secret' => 'secret-de-test'], '/api/v1/articles', ['laravel_maintenance' => $cookie]))->toBeNull()
        ->and(maintenanceAnswer(['template' => 'x', 'secret' => 'secret-de-test'], '/api/v1/articles', ['laravel_maintenance' => 'forgé'])['status'])->toBe(503);
});

it('names maintenance rather than a fault when the framework answers it', function (): void {
    // What PreventRequestsDuringMaintenance throws without --render.
    Route::get('/api/v1/maintenance-de-controle', function (): void {
        throw new HttpException(503, 'Service Unavailable', null, ['Retry-After' => '60']);
    });

    $this->getJson('/api/v1/maintenance-de-controle')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '60')
        ->assertJsonPath('error', 'MAINTENANCE')
        ->assertJsonPath('message', ApiErrorCode::MAINTENANCE->message());
});

it('documents every error code with its status', function (): void {
    $documented = json_decode((string) file_get_contents(resource_path('openapi/v1.json')), true)['x-error-codes'];

    $expected = [];
    foreach (ApiErrorCode::cases() as $code) {
        $expected[$code->value] = $code->httpStatus();
    }

    ksort($documented);
    ksort($expected);

    expect($documented)->toBe($expected);
});

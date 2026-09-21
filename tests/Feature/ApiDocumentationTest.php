<?php

declare(strict_types=1);

use App\Domain\Dolinews\Api\OpenApiSpec;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Documentation of the public API (SPEC 5.2): the OpenAPI document
 * served to client generators, the page rendered from it, and the
 * conformance of the document to the routes it claims to describe.
 */
it('serves the openapi document with the base url of the instance', function (): void {
    $response = $this->getJson('/api/v1/openapi.json');

    $response->assertOk()
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonPath('info.version', '1.0.0')
        ->assertJsonPath('servers.0.url', url('/api/v1'));

    // The one endpoint outside the {data, meta} envelope: wrapping it
    // would make the document unusable by the tools it exists for.
    $response->assertJsonMissingPath('data');
});

it('answers a revalidating client with 304', function (): void {
    $etag = (string) $this->getJson('/api/v1/openapi.json')->headers->get('ETag');

    expect($etag)->not->toBe('');

    $this->withHeaders(['If-None-Match' => $etag])
        ->getJson('/api/v1/openapi.json')
        ->assertStatus(304);
});

it('documents every route of the public api, and only real ones', function (): void {
    $documented = collect(app(OpenApiSpec::class)->endpoints())
        ->map(static fn (array $endpoint): string => $endpoint['method'].' '.$endpoint['path'])
        ->sort()
        ->values()
        ->all();

    $registered = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'api/v1/'))
        ->flatMap(static fn ($route): array => collect($route->methods())
            // Laravel registers HEAD alongside every GET, and the
            // document describes the contract, not that duplication.
            ->reject(static fn (string $method): bool => $method === 'HEAD')
            ->map(static fn (string $method): string => $method.' /'.substr($route->uri(), strlen('api/v1/')))
            ->all())
        ->sort()
        ->values()
        ->all();

    // Both directions on purpose: an endpoint added without
    // documentation fails here, and so does a documented endpoint that
    // no longer exists. A specification nobody can trust is worse than
    // none, since a client generator builds on it blindly.
    expect($documented)->toBe($registered);
});

it('resolves every $ref of the document', function (): void {
    // operationsByTag walks the whole tree and throws on a dangling
    // pointer: a typo in a $ref must fail the suite, not surface as a
    // blank column on the public page.
    $groups = app(OpenApiSpec::class)->operationsByTag();

    expect($groups)->not->toBeEmpty();

    foreach ($groups as $group) {
        foreach ($group['operations'] as $operation) {
            expect($operation)->toHaveKeys(['method', 'path', 'summary', 'response_rows']);
            expect($operation['response_rows'])->not->toBeEmpty();
        }
    }
});

it('renders the api documentation page from the specification', function (): void {
    $response = $this->get('/documentation-api');

    $response->assertOk()
        // The contract, as the page states it.
        ->assertSee(url('/api/v1'))
        ->assertSee('POST')
        ->assertSee('/articles/{id}/submit')
        ->assertSee('Authorization: Bearer', escape: false)
        // The invariant a developer must read here before writing one
        // line of integration (SPEC D6, 5.1).
        ->assertSee('submit', escape: false)
        ->assertSee('never the right to publish')
        // Error codes are the branching contract of a client.
        ->assertSee('QUOTA_BUCKET_EMPTY')
        ->assertSee('CONTRIBUTOR_REQUIRED');
});

it('links the documentation from the public footer and the token page', function (): void {
    $this->get('/')->assertOk()->assertSee('/documentation-api');

    $user = User::factory()->create();

    $this->actingAs($user)->get('/account/tokens')
        ->assertOk()
        ->assertSee('/documentation-api');
});

it('translates the page chrome without translating the contract', function (): void {
    $this->from('/')->get('/locale/en');

    $response = $this->get('/documentation-api');

    // The chrome follows the interface locale (D14)...
    $response->assertOk()->assertSee('API documentation');

    // ...while the specification stays in the language it is written
    // in: an integrator reads the contract, not a translation of it.
    $response->assertSee('never the right to publish');
});

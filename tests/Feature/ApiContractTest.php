<?php

declare(strict_types=1);

use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Factory;

/**
 * Public API contract (SPEC 5.2, D12): envelope, frozen date format,
 * submission circuit identical to the web surface, media deposit.
 */
it('answers unauthenticated calls with the json error envelope', function (): void {
    $response = $this->getJson('/api/v1/profile');

    $response->assertStatus(401)
        ->assertJsonPath('error', 'INVALID_TOKEN')
        ->assertJsonStructure(['error', 'message']);
});

it('lists published articles in the success envelope with frozen dates', function (): void {
    $article = Factory::publishedArticle(User::factory()->create());

    $response = $this->getJson('/api/v1/articles');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'title', 'published_at']],
            'meta',
        ]);

    // S12: the frozen date format is the contract clients depend on.
    expect($response->json('data.0.published_at'))
        ->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

it('refuses writes to plain reader accounts', function (): void {
    $reader = User::factory()->create();

    $this->withToken(Factory::apiToken($reader))
        ->postJson('/api/v1/articles', [
            'type' => 'release',
            'editor_id' => 1,
            'title' => 'X',
            'summary' => 'S',
            'body' => 'B',
            'locale' => 'fr_FR',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error', 'CONTRIBUTOR_REQUIRED');
});

it('creates and submits an article through the api', function (): void {
    [$user, $editor] = Factory::contributorWithEditor();

    $response = $this->withToken(Factory::apiToken($user))
        ->postJson('/api/v1/articles', [
            'type' => 'release',
            'editor_id' => $editor->getKey(),
            'title' => 'Module Z 3.0',
            'summary' => 'Nouvelle version majeure.',
            'body' => '## Details',
            'locale' => 'fr_FR',
            'focus' => 'feature_major',
            'maturity' => 'stable',
            'compat_status' => 'tested',
            'dolibarr_min' => 20,
            'dolibarr_max' => 22,
            'submit' => true,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'pending');

    // The API grants the right to submit, never to publish (D12).
    $this->assertDatabaseHas('articles', [
        'title' => 'Module Z 3.0',
        'status' => 'pending',
    ]);
});

it('rejects malformed submissions with the validation envelope', function (): void {
    [$user, $editor] = Factory::contributorWithEditor();

    $this->withToken(Factory::apiToken($user))
        ->postJson('/api/v1/articles', [
            'type' => 'release',
            'editor_id' => $editor->getKey(),
            'title' => '',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'VALIDATION_FAILED');
});

it('deposits media through the two-step publication', function (): void {
    Storage::fake(Media::DISK);

    [$user, $editor] = Factory::contributorWithEditor();

    $binary = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        true,
    );

    $response = $this->withToken(Factory::apiToken($user))
        ->post('/api/v1/media', [
            'editor_id' => $editor->getKey(),
            'alt' => 'Capture du module',
            'file' => UploadedFile::fake()->createWithContent('capture.png', $binary),
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.mime', 'image/png');

    expect($response->json('data.url'))->toBeString()
        ->and($response->json('data.warning'))->toContain('donnees reelles');
});

it('logs authenticated api calls for observability', function (): void {
    [$user] = Factory::contributorWithEditor();

    $this->withToken(Factory::apiToken($user))->getJson('/api/v1/profile')->assertOk();

    $this->assertDatabaseHas('api_requests', [
        'user_id' => $user->getKey(),
        'path' => 'api/v1/profile',
    ]);
});

it('serves the project directory', function (): void {
    $author = User::factory()->create();
    $editor = (new EditorService)->create($author, [
        'name' => 'Editeur annuaire',
        'contact_email' => 'dir@editeur.test',
    ]);

    $this->getJson('/api/v1/projects')->assertOk()
        ->assertJsonStructure(['data', 'meta']);
});

<?php

declare(strict_types=1);

use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Media\MediaService;
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

it('binds deposited media to the article that references them', function (): void {
    Storage::fake(Media::DISK);

    [$user, $editor] = Factory::contributorWithEditor();
    $token = Factory::apiToken($user);

    $binary = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        true,
    );

    $mediaId = $this->withToken($token)
        ->post('/api/v1/media', [
            'editor_id' => $editor->getKey(),
            'alt' => 'Interface du module',
            'file' => UploadedFile::fake()->createWithContent('interface.png', $binary),
        ])
        ->assertStatus(201)
        ->json('data.id');

    // Orphan until an article claims it: that is what the purge targets.
    $this->assertDatabaseHas('media', ['id' => $mediaId, 'article_id' => null]);

    $articleId = $this->withToken($token)
        ->postJson('/api/v1/articles', [
            'type' => 'release',
            'editor_id' => $editor->getKey(),
            'title' => 'Module illustre 1.0',
            'summary' => 'Version illustree par une capture.',
            'body' => '## Details',
            'locale' => 'fr_FR',
            'media_ids' => [$mediaId],
        ])
        ->assertStatus(201)
        ->json('data.id');

    $this->assertDatabaseHas('media', [
        'id' => $mediaId,
        'article_id' => $articleId,
    ]);
});

it('refuses to bind media belonging to another editor', function (): void {
    Storage::fake(Media::DISK);

    [$user, $editor] = Factory::contributorWithEditor();
    [, $otherEditor] = Factory::contributorWithEditor();

    $binary = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        true,
    );

    // Deposited through the service rather than a second HTTP call: one
    // authenticated request per test keeps the acting token unambiguous.
    $foreignMediaId = app(MediaService::class)->store(
        UploadedFile::fake()->createWithContent('autre.png', $binary),
        $otherEditor,
    )->getKey();

    $this->withToken(Factory::apiToken($user))
        ->postJson('/api/v1/articles', [
            'type' => 'release',
            'editor_id' => $editor->getKey(),
            'title' => 'Module emprunteur 1.0',
            'summary' => 'Tente de reprendre le media d\'un autre editeur.',
            'body' => '## Details',
            'locale' => 'fr_FR',
            'media_ids' => [$foreignMediaId],
        ])
        ->assertStatus(201);

    $this->assertDatabaseHas('media', [
        'id' => $foreignMediaId,
        'article_id' => null,
    ]);
});

it('logs authenticated api calls for observability', function (): void {
    [$user] = Factory::contributorWithEditor();

    $this->withToken(Factory::apiToken($user))->getJson('/api/v1/profile')->assertOk();

    $this->assertDatabaseHas('api_requests', [
        'user_id' => $user->getKey(),
        'path' => 'api/v1/profile',
    ]);
});

it('keeps sheet creation behind a contributor token', function (): void {
    // Writing a sheet is a write: reading the directory is not.
    $this->postJson('/api/v1/projects', [
        'name' => 'Module anonyme',
        'summary' => 'Fiche deposee sans jeton.',
        'editor_id' => 1,
    ])->assertStatus(401)->assertJsonPath('error', 'INVALID_TOKEN');

    [$user, $editor] = Factory::contributorWithEditor();

    $this->withToken(Factory::apiToken($user))
        ->postJson('/api/v1/projects', [
            'name' => 'Module identifie',
            'summary' => 'Fiche deposee avec un jeton contributeur.',
            'editor_id' => $editor->getKey(),
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.slug', 'module-identifie');
});

it('creates the editor the account publishes for', function (): void {
    $user = Factory::contributorWithoutEditor();

    $response = $this->withToken(Factory::apiToken($user))
        ->postJson('/api/v1/editors', [
            'name' => 'Editeur autonome',
            'contact_email' => 'contact@editeur.test',
            'website' => 'https://editeur.test',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.slug', 'editeur-autonome')
        // Creating is not being verified: that stays a moderation act.
        ->assertJsonPath('data.verified', false);

    $this->assertDatabaseHas('editor_user', [
        'editor_id' => $response->json('data.id'),
        'user_id' => $user->getKey(),
        'role' => 'owner',
    ]);
});

it('keeps editor creation behind a contributor token', function (): void {
    $reader = User::factory()->create();

    $this->withToken(Factory::apiToken($reader))
        ->postJson('/api/v1/editors', [
            'name' => 'Editeur lecteur',
            'contact_email' => 'lecteur@editeur.test',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error', 'CONTRIBUTOR_REQUIRED');
});

it('refuses a second editor to the same owner', function (): void {
    // The publication credit and the queue ceiling are counted per
    // editor (SPEC 5.3): minting editors would multiply the quota.
    [$user] = Factory::contributorWithEditor();

    $this->withToken(Factory::apiToken($user))
        ->postJson('/api/v1/editors', [
            'name' => 'Second editeur',
            'contact_email' => 'second@editeur.test',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error', 'CONFLICT');

    expect($user->editors()->count())->toBe(1);
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

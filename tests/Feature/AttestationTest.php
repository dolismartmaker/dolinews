<?php

declare(strict_types=1);

use App\Domain\Dolinews\Attestations\AttestationService;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Attestation;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Projects\ProjectService;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\Factory;

/**
 * Attestation ingestion (SPEC 10, revue F5). The indicator is
 * declarative, but what it is attached to must still be true.
 */
function attestationPayload(string $projectSlug, ?int $articleId = null): array
{
    return array_filter([
        'project' => $projectSlug,
        'article_id' => $articleId,
        'source_type' => 'captests',
        'source_url' => 'https://tests.exemple.test/rapport/1',
        'metric' => 'cas',
        'value' => '42',
    ], static fn ($value): bool => $value !== null);
}

it('records an attestation for a sheet the account owns', function (): void {
    [$user, $editor] = Factory::contributorWithEditor();

    $project = app(ProjectService::class)
        ->create($editor, ['name' => 'Module XY', 'summary' => 'Un module.']);

    $this->withToken(Factory::apiToken($user))
        ->postJson('/api/v1/attestations', attestationPayload($project->slug))
        ->assertCreated()
        ->assertJsonPath('data.display_label', 'tests publiés par l\'éditeur');
});

it('refuses an article that belongs to another project', function (): void {
    [$user, $editor] = Factory::contributorWithEditor();

    $project = app(ProjectService::class)
        ->create($editor, ['name' => 'Module XY', 'summary' => 'Un module.']);

    // An announcement of a completely different editor.
    $stray = Factory::publishedArticle(User::factory()->create());

    $this->withToken(Factory::apiToken($user))
        ->postJson('/api/v1/attestations', attestationPayload($project->slug, $stray->getKey()))
        ->assertStatus(422)
        ->assertJsonPath('error', 'VALIDATION_FAILED')
        ->assertJsonPath('detail.article_id.0', 'Cet article n\'appartient pas au projet visé.');
});

it('refuses a sheet the account does not belong to', function (): void {
    [, $editor] = Factory::contributorWithEditor();

    $project = app(ProjectService::class)
        ->create($editor, ['name' => 'Module XY', 'summary' => 'Un module.']);

    $stranger = Factory::contributorWithoutEditor();

    $this->withToken(Factory::apiToken($stranger))
        ->postJson('/api/v1/attestations', attestationPayload($project->slug))
        ->assertStatus(403)
        ->assertJsonPath('error', 'FORBIDDEN');
});

/**
 * Where an attestation is read (SPEC 10): on the sheet, and on the
 * announcement of the version it was measured on, in every language.
 */
function recordAttestation(Project $project, ?int $articleId, string $url, string $value, int $daysAgo = 3): Attestation
{
    $article = $articleId === null ? null : Article::query()->findOrFail($articleId);

    return app(AttestationService::class)->record($project, [
        'source_type' => 'ci',
        'source_url' => $url,
        'metric' => 'cas',
        'value' => $value,
        'measured_at' => now()->subDays($daysAgo),
    ], $article);
}

it('shows the attestations of a version on its announcement, in every language version', function (): void {
    $author = User::factory()->create();
    $project = Factory::projectFor($author);
    $source = Factory::publishedArticle($author, ['project_id' => $project->getKey(), 'version' => '2.1']);
    $translation = Factory::publishedTranslation($author, $source, 'en_US');
    $other = Factory::publishedArticle($author, ['project_id' => $project->getKey(), 'version' => '2.0', 'title' => 'Module XY 2.0']);

    recordAttestation($project, $source->getKey(), 'https://ci.acme.example/run/21', '38 sur 40');
    recordAttestation($project, $other->getKey(), 'https://ci.acme.example/run/20', '12 sur 40');

    $this->get('/fr/articles/'.$source->getKey())->assertOk()
        ->assertSee('Tests publiés par l&#039;éditeur', false)
        ->assertSee('38 sur 40')
        ->assertSee('dernière exécution il y a 3 jours')
        ->assertSee('https://ci.acme.example/run/21', false)
        // Another version's tests stay on that version.
        ->assertDontSee('12 sur 40');

    // Attached to the source, read from the translation: the tests ran
    // on the version, and the wording follows the reader's language.
    $this->get('/en/articles/'.$translation->getKey())->assertOk()
        ->assertSee('Tests published by the editor')
        ->assertSee('38 sur 40')
        ->assertSee('last run 3 days ago');
});

it('shows no test box on an announcement without attestation', function (): void {
    $author = User::factory()->create();
    $project = Factory::projectFor($author);
    $article = Factory::publishedArticle($author, ['project_id' => $project->getKey()]);

    // Attached to the sheet only: it belongs there, not to a version.
    recordAttestation($project, null, 'https://ci.acme.example/run/1', '40 sur 40');

    $this->get('/fr/articles/'.$article->getKey())->assertOk()
        ->assertDontSee('Tests publiés par l&#039;éditeur', false);

    $this->get(route('projects.show', ['locale' => 'fr', 'slug' => $project->slug]))->assertOk()
        ->assertSee('40 sur 40');
});

it('checks the report behind an attestation and says when it is gone', function (): void {
    Http::fake([
        'https://93.184.216.34/*' => Http::response('', 404),
        'https://8.8.8.8/*' => Http::response('', 200),
    ]);

    $author = User::factory()->create();
    $project = Factory::projectFor($author);
    $expired = recordAttestation($project, null, 'https://93.184.216.34/run/1', '40 sur 40');
    $alive = recordAttestation($project, null, 'https://8.8.8.8/campagnes/2', '39 sur 40');

    $this->artisan('dolinews:check-links')->assertSuccessful();

    expect($expired->refresh()->is_broken)->toBeTrue()
        ->and($expired->checked_at)->not->toBeNull()
        ->and($alive->refresh()->is_broken)->toBeFalse();

    // The row stays, the reader learns the detail no longer answers.
    $this->get(route('projects.show', ['locale' => 'fr', 'slug' => $project->slug]))->assertOk()
        ->assertSee('40 sur 40')
        ->assertSee('lien cassé');
});

it('words the freshness in the reader language', function (int $days, string $expected): void {
    app()->setLocale('en');

    $attestation = new Attestation(['measured_at' => now()->subDays($days)]);

    expect(app(AttestationService::class)->freshness($attestation))->toBe($expected);
})->with([
    [0, 'last run today'],
    [1, 'last run yesterday'],
    [3, 'last run 3 days ago'],
    [60, 'last run 2 months ago'],
    [400, 'last run more than a year ago'],
]);

it('keeps a report address as long as the API accepts', function (): void {
    // 2048 is the documented limit; the column used to stop at 255,
    // which only MySQL enforced (SQLite ignores the length).
    [$user, $editor] = Factory::contributorWithEditor();
    $project = app(ProjectService::class)
        ->create($editor, ['name' => 'Module XY', 'summary' => 'Un module.']);

    $url = 'https://ci.acme.example/runs/'.str_repeat('a', 2048 - strlen('https://ci.acme.example/runs/'));

    $payload = attestationPayload($project->slug);
    $payload['source_url'] = $url;

    $id = $this->withToken(Factory::apiToken($user))
        ->postJson('/api/v1/attestations', $payload)
        ->assertCreated()
        ->json('data.id');

    expect(Attestation::query()->findOrFail($id)->source_url)->toBe($url);
});

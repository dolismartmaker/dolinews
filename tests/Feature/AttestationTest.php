<?php

declare(strict_types=1);

use App\Domain\Dolinews\Projects\ProjectService;
use App\Models\User;
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

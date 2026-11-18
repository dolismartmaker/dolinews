<?php

declare(strict_types=1);

use Tests\Support\Factory;

/**
 * The two write throttles of the public API (SPEC 5.2).
 *
 * Every write used to share the submission limit of ten calls a minute.
 * Synchronising one sheet - its fields, logo, a few screenshots, links,
 * translations - sends more than that without putting anything in front
 * of the review team, and each run stalled a full minute. Completing a
 * sheet now counts apart, under a wider limit; submissions keep theirs.
 */

/**
 * An article payload accepted by POST /articles for this editor.
 *
 * @return array<string, mixed>
 */
function throttleArticlePayload(int $editorId): array
{
    return [
        'editor_id' => $editorId,
        'type' => 'announcement',
        'title' => 'Titre de contrôle',
        'summary' => 'Résumé de contrôle.',
        'body' => 'Corps de contrôle.',
        'locale' => 'fr_FR',
    ];
}

it('completes a sheet once the submission limit is spent', function (): void {
    [$owner, $editor] = Factory::contributorWithEditor();
    $project = Factory::projectFor($owner);
    $token = Factory::apiToken($owner);

    // Spend the submission limit: the eleventh article is refused.
    $response = null;

    for ($attempt = 0; $attempt < 11; $attempt++) {
        $response = $this->withToken($token)
            ->postJson('/api/v1/articles', throttleArticlePayload((int) $editor->getKey()));
    }

    $response->assertStatus(429)->assertJsonPath('error', 'RATE_LIMITED');

    // Fifteen corrections of the sheet in the same minute: more than the
    // submission limit, and none of them is held back by it.
    for ($attempt = 1; $attempt <= 15; $attempt++) {
        $this->withToken($token)
            ->patchJson('/api/v1/projects/'.$project->slug, ['summary' => 'Résumé numéro '.$attempt.'.'])
            ->assertOk();
    }

    expect($project->refresh()->summary)->toBe('Résumé numéro 15.');
});

it('bounds sheet completions by their own setting', function (): void {
    config()->set('dolinews.api.attach_per_minute', 3);

    [$owner] = Factory::contributorWithEditor();
    $project = Factory::projectFor($owner);
    $token = Factory::apiToken($owner);

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->withToken($token)
            ->patchJson('/api/v1/projects/'.$project->slug, ['summary' => 'Résumé numéro '.$attempt.'.'])
            ->assertOk();
    }

    $refused = $this->withToken($token)
        ->patchJson('/api/v1/projects/'.$project->slug, ['summary' => 'Résumé de trop.']);

    $refused->assertStatus(429)
        ->assertJsonPath('error', 'RATE_LIMITED')
        ->assertHeader('Retry-After');

    expect((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and($project->refresh()->summary)->toBe('Résumé numéro 3.');
});

it('reads the submission limit from the configuration', function (): void {
    config()->set('dolinews.api.write_per_minute', 2);

    [$author, $editor] = Factory::contributorWithEditor();
    $token = Factory::apiToken($author);

    foreach ([1, 2] as $attempt) {
        $this->withToken($token)
            ->postJson('/api/v1/articles', throttleArticlePayload((int) $editor->getKey()))
            ->assertCreated();
    }

    $this->withToken($token)
        ->postJson('/api/v1/articles', throttleArticlePayload((int) $editor->getKey()))
        ->assertStatus(429)
        ->assertJsonPath('error', 'RATE_LIMITED');
});

it('states both write limits on the documentation page', function (): void {
    $this->get('/fr/documentation-api')
        ->assertOk()
        ->assertSee('Compléments sans revue')
        ->assertSee('60 requests per minute and per account, counted apart from the write limit')
        ->assertSee('limite des compléments');

    $this->get('/en/documentation-api')
        ->assertOk()
        ->assertSee('Additions without review')
        ->assertSee('additions limit');
});

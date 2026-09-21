<?php

declare(strict_types=1);

use App\Domain\Dolinews\Projects\ProjectService;
use App\Models\User;
use Tests\Support\Factory;

/**
 * Every URL stored then rendered in an href is restricted to http and
 * https (revue M4). Blade escaping does not protect against a clickable
 * href="javascript:...", so the refusal has to happen at validation.
 */
$refused = [
    'javascript:alert(1)',
    'javascript://x/%0aalert(1)',
    'JaVaScRiPt:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'data://text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
    'vbscript:msgbox(1)',
    'file:///etc/passwd',
    'ftp://exemple.test/fichier',
    'gopher://exemple.test/',
];

it('refuses a non-web scheme on the profile website', function (string $url): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/account')
        ->post('/account', [
            'name' => 'Candidat',
            'website' => $url,
        ])
        ->assertSessionHasErrors('website');

    expect($user->fresh()?->website)->toBeNull();
})->with($refused);

it('refuses a non-web scheme on the editor website', function (string $url): void {
    $user = Factory::contributorWithoutEditor();

    $this->actingAs($user)
        ->from('/account')
        ->post('/account/editors', [
            'name' => 'Editeur test',
            'contact_email' => 'contact@editeur.test',
            'website' => $url,
        ])
        ->assertSessionHasErrors('website');
})->with($refused);

it('refuses a non-web scheme on an attestation source', function (string $url): void {
    [$user, $editor] = Factory::contributorWithEditor();

    $project = app(ProjectService::class)
        ->create($editor, ['name' => 'Module XY', 'summary' => 'Un module.']);

    $this->withToken(Factory::apiToken($user))
        ->postJson('/api/v1/attestations', [
            'project' => $project->slug,
            'source_type' => 'captests',
            'source_url' => $url,
            'metric' => 'cas',
            'value' => '42',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'VALIDATION_FAILED');
})->with($refused);

it('still accepts an ordinary web address', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/account', [
            'name' => 'Candidat',
            'website' => 'https://exemple.test/profil',
        ])
        ->assertSessionHasNoErrors();

    expect($user->fresh()?->website)->toBe('https://exemple.test/profil');
});

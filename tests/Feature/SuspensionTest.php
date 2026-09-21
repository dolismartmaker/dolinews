<?php

declare(strict_types=1);

use App\Domain\Dolinews\Moderation\ModerationService;
use App\Models\User;
use Tests\Support\Factory;

/**
 * A suspension bites at once, on every surface (SPEC 9.3, revue M3).
 */
it('cuts a suspended account off its live web session', function (string $path): void {
    [$user] = Factory::contributorWithEditor();

    $this->actingAs($user)->get('/account/articles')->assertOk();

    $user->forceFill(['active' => false])->save();

    $this->actingAs($user)->get($path)
        ->assertRedirect(route('login'));
})->with([
    '/account',
    '/account/articles',
    '/account/articles/new',
    '/account/projects',
    '/account/tokens',
]);

it('stops a suspended account from submitting mid-session', function (): void {
    [$user, $editor] = Factory::contributorWithEditor();

    $user->forceFill(['active' => false])->save();

    $this->actingAs($user)
        ->post('/account/articles', [
            'type' => 'release',
            'editor_id' => $editor->getKey(),
            'title' => 'Passee entre les mailles',
            'summary' => 'Resume',
            'body' => 'Corps',
            'locale' => 'fr_FR',
        ])
        ->assertRedirect(route('login'));
});

it('leaves an active account alone', function (): void {
    [$user] = Factory::contributorWithEditor();

    $this->actingAs($user)->get('/account/articles')->assertOk();
});

it('revokes the credentials of the account it suspends', function (): void {
    $target = User::factory()->create();
    $target->forceFill(['remember_token' => 'cookie-vivant'])->save();
    Factory::apiToken($target);

    $moderator = User::factory()->superAdmin()->create();

    app(ModerationService::class)->suspend(
        $target,
        $moderator,
        'propos contraires aux règles',
        'R3',
    );

    expect($target->fresh()?->active)->toBeFalse()
        ->and($target->tokens()->count())->toBe(0)
        ->and($target->fresh()?->getRememberToken())->not->toBe('cookie-vivant');
});

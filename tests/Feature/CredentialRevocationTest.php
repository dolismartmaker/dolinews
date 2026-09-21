<?php

declare(strict_types=1);

use App\Core\Auth\CredentialRevoker;
use App\Core\Auth\TokenLifetime;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Support\Factory;

/**
 * What survives a password reset, and what must not (revue E/M2).
 */
it('gives every minted token a term', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/account/tokens', ['name' => 'chaine de publication'])
        ->assertRedirect();

    $token = $user->tokens()->firstOrFail();

    expect($token->expires_at)->not->toBeNull()
        ->and($token->expires_at->greaterThan(now()))->toBeTrue();
});

it('computes the term from the single sanctum setting', function (): void {
    config()->set('sanctum.expiration', 60);

    expect(TokenLifetime::expiresAt()?->diffInMinutes(now(), true))->toBeGreaterThan(58);

    config()->set('sanctum.expiration', 0);

    expect(TokenLifetime::expiresAt())->toBeNull();
});

it('revokes tokens and the remember cookie on a password reset', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'candidat@exemple.test']);
    $user->forceFill(['remember_token' => 'jeton-vole'])->save();

    Factory::apiToken($user);

    expect($user->tokens()->count())->toBe(1);

    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'candidat@exemple.test',
        'password' => 'un-mot-de-passe-long',
        'password_confirmation' => 'un-mot-de-passe-long',
    ])->assertRedirect(route('login'));

    expect($user->tokens()->count())->toBe(0)
        ->and($user->fresh()?->getRememberToken())->not->toBe('jeton-vole');
});

it('drops the stored sessions of the account it revokes', function (): void {
    // The suite runs on the array driver; production stores sessions in
    // the database, which is the only driver where they can be found by
    // account at all.
    config()->set('session.driver', 'database');

    $user = User::factory()->create();

    DB::table('sessions')->insert([
        'id' => 'session-vivante',
        'user_id' => $user->getKey(),
        'ip_address' => '203.0.113.7',
        'user_agent' => 'test',
        'payload' => '',
        'last_activity' => time(),
    ]);

    app(CredentialRevoker::class)->revokeAllExceptPassword($user);

    expect(DB::table('sessions')->where('user_id', $user->getKey())->count())->toBe(0);
});

it('sends the reset notification the flow depends on', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'candidat@exemple.test']);

    $this->post('/forgot-password', ['email' => 'candidat@exemple.test']);

    Notification::assertSentTo($user, ResetPassword::class);
});

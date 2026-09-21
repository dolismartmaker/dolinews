<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditEntry;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Factory;

/**
 * Password change from the account, and the forced change the seeded
 * super admin lands on (revue D4).
 */
it('holds an account carrying its installation password on the password screen', function (string $path): void {
    $admin = User::factory()->superAdmin()->mustChangePassword()->create();

    $this->actingAs($admin)->get($path)->assertRedirect(route('account.password'));
})->with([
    '/account',
    '/account/tokens',
    '/admin',
    '/admin/users',
]);

it('lets that account reach the password screen and log out', function (): void {
    $admin = User::factory()->superAdmin()->mustChangePassword()->create();

    $this->actingAs($admin)->get('/account/password')->assertOk();

    $this->actingAs($admin)->post('/logout')->assertRedirect();
});

it('lifts the flag and reopens the service once the password changes', function (): void {
    $admin = User::factory()->superAdmin()->mustChangePassword()->create([
        'password' => Hash::make('mot-de-passe-installation'),
    ]);

    $this->actingAs($admin)
        ->post('/account/password', [
            'current_password' => 'mot-de-passe-installation',
            'password' => 'un-autre-mot-de-passe',
            'password_confirmation' => 'un-autre-mot-de-passe',
        ])
        ->assertRedirect(route('account.show'));

    $fresh = $admin->fresh();

    expect($fresh?->must_change_password)->toBeFalse()
        ->and(Hash::check('un-autre-mot-de-passe', (string) $fresh?->password))->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'password.changed')->count())->toBe(1);

    $this->actingAs($fresh)->get('/admin')->assertOk();
});

it('refuses the change without the current password', function (): void {
    $user = User::factory()->create(['password' => Hash::make('mot-de-passe-actuel')]);

    $this->actingAs($user)
        ->from('/account/password')
        ->post('/account/password', [
            'current_password' => 'pas-le-bon',
            'password' => 'un-autre-mot-de-passe',
            'password_confirmation' => 'un-autre-mot-de-passe',
        ])
        ->assertSessionHasErrors('current_password');

    expect(Hash::check('mot-de-passe-actuel', (string) $user->fresh()?->password))->toBeTrue();
});

it('revokes the API tokens of the account that changes its password', function (): void {
    $user = User::factory()->create(['password' => Hash::make('mot-de-passe-actuel')]);
    Factory::apiToken($user);

    $this->actingAs($user)
        ->post('/account/password', [
            'current_password' => 'mot-de-passe-actuel',
            'password' => 'un-autre-mot-de-passe',
            'password_confirmation' => 'un-autre-mot-de-passe',
        ])
        ->assertRedirect(route('account.show'));

    expect($user->tokens()->count())->toBe(0);
});

it('seeds the super admin with the flag raised', function (): void {
    config()->set('dolinews.super_admin.email', 'admin@dolinews.test');
    config()->set('dolinews.super_admin.password', 'mot-de-passe-environnement');

    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', 'admin@dolinews.test')->firstOrFail();

    expect($admin->is_super_admin)->toBeTrue()
        ->and($admin->must_change_password)->toBeTrue()
        ->and(Hash::check('mot-de-passe-environnement', (string) $admin->password))->toBeTrue();
});

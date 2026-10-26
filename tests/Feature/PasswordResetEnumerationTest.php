<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/**
 * The forgotten password form answers the same for a registered and an
 * unknown address: it must not tell which addresses have an account.
 */
it('answers an unknown address exactly like a registered one', function (): void {
    Notification::fake();

    User::factory()->create(['email' => 'inscrit@exemple.test']);

    $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'inscrit@exemple.test']);
    $known->assertRedirect('/forgot-password')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __(Password::RESET_LINK_SENT));

    $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'inconnu@exemple.test']);
    $unknown->assertRedirect('/forgot-password')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __(Password::RESET_LINK_SENT));

    Notification::assertSentTimes(ResetPassword::class, 1);
});

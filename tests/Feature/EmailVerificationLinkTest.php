<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * A verification link only validates the account it was mailed to,
 * whatever account is signed in when it is opened.
 */
function verificationUrl(User $user): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);
}

it('refuses a verification link opened from another account', function (): void {
    $owner = User::factory()->unverified()->create();
    $other = User::factory()->create();

    $this->actingAs($other)->get(verificationUrl($owner))->assertForbidden();

    expect($owner->refresh()->hasVerifiedEmail())->toBeFalse();
});

it('validates the address when its own account opens the link', function (): void {
    $owner = User::factory()->unverified()->create();

    $this->actingAs($owner)->get(verificationUrl($owner))->assertRedirect(route('account.show'));

    expect($owner->refresh()->hasVerifiedEmail())->toBeTrue();
});

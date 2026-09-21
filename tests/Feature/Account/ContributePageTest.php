<?php

declare(strict_types=1);

use App\Domain\Dolinews\Models\ContributorProof;
use App\Models\User;
use Tests\Support\Factory;

/**
 * The contribution screen of an account (SPEC 3): what it offers depends on
 * whether the account is already qualified.
 */
it('offers the qualification forms to an account that cannot publish yet', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('account.contribute'))
        ->assertOk()
        ->assertSee('Vérification de contribution')
        ->assertSee('Validation manuelle');
});

it('drops the qualification forms once the account carries an active proof', function (): void {
    // Asking a contributor to prove a contribution they have already proved
    // reads as a failure of the previous step.
    $user = Factory::contributorWithoutEditor();

    $this->actingAs($user)
        ->get(route('account.contribute'))
        ->assertOk()
        ->assertSee('Votre compte peut publier')
        ->assertDontSee('Vérification de contribution')
        ->assertDontSee('Code reçu par courriel')
        ->assertDontSee('Validation manuelle');
});

it('brings the qualification forms back when the proof is revoked', function (): void {
    // isContributor() only counts proofs that are not revoked, so the way
    // back opens on its own: nothing in the view has to remember it.
    $user = Factory::contributorWithoutEditor();

    $proof = ContributorProof::query()->where('user_id', $user->getKey())->firstOrFail();
    $proof->revoked_at = now();
    $proof->save();

    $this->actingAs($user)
        ->get(route('account.contribute'))
        ->assertOk()
        ->assertSee('Vérification de contribution')
        ->assertDontSee('Votre compte peut publier');
});

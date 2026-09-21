<?php

declare(strict_types=1);

use App\Models\User;
use Tests\Support\Factory;

/**
 * From the contribution proof to the editor one publishes under
 * (SPEC 3.1/4.1).
 *
 * The proof qualifies an account, it does not say who publishes: a
 * contributor without an editor can submit nothing, neither from the
 * web nor through the API. The qualification page therefore carries
 * that next step rather than congratulating the account and stopping
 * there.
 */
it('offers editor creation right after the contribution proof', function (): void {
    $user = Factory::contributorWithoutEditor();

    $this->actingAs($user)->get('/account/contribute')
        ->assertOk()
        ->assertSee('Votre compte peut publier', escape: false)
        ->assertSee('Créer un éditeur', escape: false)
        ->assertSee(route('account.editors.store'));
});

it('sends a contributor who already has an editor to the article space', function (): void {
    [$user] = Factory::contributorWithEditor();

    $this->actingAs($user)->get('/account/contribute')
        ->assertOk()
        ->assertSee(route('account.articles'))
        ->assertDontSee('Créer un éditeur', escape: false);
});

it('never offers editor creation to an account without a proof', function (): void {
    $reader = User::factory()->create();

    $this->actingAs($reader)->get('/account/contribute')
        ->assertOk()
        ->assertDontSee('Créer un éditeur', escape: false);
});

it('creates the editor from the web form and makes the account its owner', function (): void {
    $user = Factory::contributorWithoutEditor();

    $this->actingAs($user)->post(route('account.editors.store'), [
        'name' => 'Editeur du web',
        'contact_email' => 'web@editeur.test',
    ])->assertRedirect(route('account.articles'));

    $this->assertDatabaseHas('editors', [
        'slug' => 'editeur-du-web',
        'verified_at' => null,
    ]);

    expect($user->editors()->first()?->pivot->role)->toBe('owner');
});

it('refuses editor creation to a reader account on the web too', function (): void {
    $reader = User::factory()->create();

    $this->actingAs($reader)->post(route('account.editors.store'), [
        'name' => 'Editeur lecteur',
        'contact_email' => 'lecteur@editeur.test',
    ])->assertSessionHasErrors('name');

    $this->assertDatabaseMissing('editors', ['slug' => 'editeur-lecteur']);
});

it('refuses a second owned editor on the web too', function (): void {
    [$user] = Factory::contributorWithEditor();

    $this->actingAs($user)->post(route('account.editors.store'), [
        'name' => 'Second editeur',
        'contact_email' => 'second@editeur.test',
    ])->assertSessionHasErrors('name');

    expect($user->editors()->count())->toBe(1);
});

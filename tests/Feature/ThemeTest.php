<?php

declare(strict_types=1);

use App\Models\User;

/**
 * Light or dark theme: the system proposes, the visitor decides.
 *
 * The choice lives in the session and reaches the page as a class on <html>,
 * which is what the dark variant of app.css keys on. No JavaScript is involved
 * anywhere, so this works on the public pages too.
 */
it('follows the system until the visitor chooses', function (): void {
    // theme-auto and not "dark": the class hands the decision back to
    // prefers-color-scheme rather than forcing either side.
    $this->get('/fr')->assertOk()->assertSee('class="theme-auto"', escape: false);
});

it('applies the chosen theme and keeps it across pages', function (string $choice, string $class): void {
    $this->from('/fr')->get('/theme/'.$choice)->assertRedirect('/fr');

    $this->get('/fr')->assertOk()->assertSee('<html lang="fr" class="'.$class.'"', escape: false);
    $this->get('/fr/regles')->assertOk()->assertSee('<html lang="fr" class="'.$class.'"', escape: false);
})->with([
    ['dark', 'dark'],
    ['auto', 'theme-auto'],
    // Light carries no class at all: nothing matches the dark variant, so the
    // sheet stays light whatever the machine is set to.
    ['light', ''],
]);

it('ignores a theme the service does not offer', function (): void {
    $this->from('/fr')->get('/theme/dark')->assertRedirect('/fr');
    $this->from('/fr')->get('/theme/sepia')->assertRedirect('/fr');

    // The previous choice stands rather than falling back silently.
    $this->get('/fr')->assertOk()->assertSee('class="dark"', escape: false);
});

it('offers the switch on the public pages and the guest screens', function (string $uri): void {
    $this->get($uri)->assertOk()
        ->assertSee(route('theme.switch', ['theme' => 'light']))
        ->assertSee(route('theme.switch', ['theme' => 'dark']));
})->with(['/fr', '/login', '/fr/regles']);

it('carries the choice into the back-office', function (): void {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)->from('/admin')->get('/theme/light')->assertRedirect('/admin');

    $this->actingAs($moderator)->get('/admin')->assertOk()
        ->assertSee('<html lang="fr" class=""', escape: false)
        ->assertSee(route('theme.switch', ['theme' => 'auto']));
});

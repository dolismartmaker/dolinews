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
    $this->get('/')->assertOk()->assertSee('class="theme-auto"', escape: false);
});

it('applies the chosen theme and keeps it across pages', function (string $choice, string $class): void {
    $this->from('/')->get('/theme/'.$choice)->assertRedirect('/');

    $this->get('/')->assertOk()->assertSee('<html lang="fr" class="'.$class.'"', escape: false);
    $this->get('/regles')->assertOk()->assertSee('<html lang="fr" class="'.$class.'"', escape: false);
})->with([
    ['dark', 'dark'],
    ['auto', 'theme-auto'],
    // Light carries no class at all: nothing matches the dark variant, so the
    // sheet stays light whatever the machine is set to.
    ['light', ''],
]);

it('ignores a theme the service does not offer', function (): void {
    $this->from('/')->get('/theme/dark')->assertRedirect('/');
    $this->from('/')->get('/theme/sepia')->assertRedirect('/');

    // The previous choice stands rather than falling back silently.
    $this->get('/')->assertOk()->assertSee('class="dark"', escape: false);
});

it('offers the switch on the public pages and the guest screens', function (string $uri): void {
    $this->get($uri)->assertOk()
        ->assertSee(route('theme.switch', ['theme' => 'light']))
        ->assertSee(route('theme.switch', ['theme' => 'dark']));
})->with(['/', '/login', '/regles']);

it('carries the choice into the back-office', function (): void {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)->from('/admin')->get('/theme/light')->assertRedirect('/admin');

    $this->actingAs($moderator)->get('/admin')->assertOk()
        ->assertSee('<html lang="fr" class=""', escape: false)
        ->assertSee(route('theme.switch', ['theme' => 'auto']));
});

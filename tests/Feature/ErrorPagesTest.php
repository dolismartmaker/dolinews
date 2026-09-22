<?php

declare(strict_types=1);

/**
 * What a reader sees when an address leads nowhere.
 *
 * Laravel's own page answers "Not Found" in English, with no navigation
 * and no sign of the service, on a site translated into ten languages.
 * The reader who follows a link cut in half by a mail client has
 * nothing left but the back button.
 */
it('serves the service own page on an unknown address', function (): void {
    $this->get('/fr/inconnu')
        ->assertNotFound()
        ->assertSee('Page introuvable')
        ->assertSee('DoliNews')
        ->assertSee(route('home', ['locale' => 'fr']));
});

it('answers in the language of the address', function (): void {
    // The address carries the language (SPEC 6.5), and an address no
    // route serves never goes through SetLocale: without reading the
    // segment here, a Polish reader who mistypes lands on French.
    $response = $this->get('/pl/inconnu');

    $response->assertNotFound()
        ->assertSee('Nie znaleziono strony')
        ->assertSee('lang="pl"', escape: false);
});

it('keeps the feed one link away', function (): void {
    $this->get('/adresse-sans-langue')
        ->assertNotFound()
        ->assertSee(route('home'));
});

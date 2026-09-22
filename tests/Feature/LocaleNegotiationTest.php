<?php

declare(strict_types=1);

/**
 * Interface locale at arrival (D14).
 *
 * The service is translated into nine languages; opening in French for
 * everyone wastes eight of them, since a reader has to find a switch
 * written in a language they did not ask for before the site speaks
 * theirs.
 *
 * Since the language lives in the address (SPEC 6.5), negotiation has
 * one job left and it is the bare root's: naming the language of a
 * visitor who has not named one, and sending them to that address.
 */
it('sends a visitor to the feed in the language of their browser', function (): void {
    $this->withHeaders(['Accept-Language' => 'es-ES,es;q=0.9,en;q=0.8'])
        ->get('/')
        ->assertRedirect('/es');

    $this->withHeaders(['Accept-Language' => 'es-ES'])
        ->get('/es')
        ->assertOk()
        ->assertSee('Anuncios del ecosistema Dolibarr', escape: false)
        ->assertSee('lang="es"', escape: false);
});

it('reads a bare language tag as well as a regional one', function (): void {
    $this->withHeaders(['Accept-Language' => 'de'])->get('/')->assertRedirect('/de');
    $this->withHeaders(['Accept-Language' => 'pt-BR,pt;q=0.9'])->get('/')->assertRedirect('/pt');
});

it('honours the quality values rather than the first tag', function (): void {
    // A browser listing Japanese first but offering Italian next: the
    // offered language wins, not the position.
    $this->withHeaders(['Accept-Language' => 'ja;q=0.9,it;q=0.8'])
        ->get('/')
        ->assertRedirect('/it');
});

it('falls back to the source language for a language it does not offer', function (): void {
    $this->withHeaders(['Accept-Language' => 'zh-CN,zh;q=0.9'])
        ->get('/')
        ->assertRedirect('/fr');
});

it('opens in the source language without any header', function (): void {
    $this->get('/')->assertRedirect('/fr');
    $this->get('/fr')->assertOk()->assertSee('lang="fr"', escape: false);
});

it('serves the language of the address whatever the browser asks', function (): void {
    // The whole point of putting the language in the path: a link
    // shared in Polish opens in Polish for its Spanish recipient.
    $this->withHeaders(['Accept-Language' => 'es-ES'])
        ->get('/pl')
        ->assertOk()
        ->assertSee('lang="pl"', escape: false);
});

it('lets the switch override the browser where no address carries the language', function (): void {
    // The account and the back-office have no language segment: there,
    // someone who asks for a language means it, and it must not flip
    // back on the next page.
    $this->withHeaders(['Accept-Language' => 'es-ES'])->get('/locale/pl');

    $this->withHeaders(['Accept-Language' => 'es-ES'])
        ->get('/')
        ->assertRedirect('/pl');
});

it('refuses a locale the service does not offer in the switch', function (): void {
    $this->withHeaders(['Accept-Language' => 'es-ES'])->get('/locale/ja');

    // The unknown choice is ignored, so negotiation still applies.
    $this->withHeaders(['Accept-Language' => 'es-ES'])
        ->get('/')
        ->assertRedirect('/es');
});

it('refuses a language segment the service does not offer', function (): void {
    $this->get('/ja')->assertNotFound();
});

it('tells caches that the answer depends on the header, and only there', function (): void {
    $root = $this->withHeaders(['Accept-Language' => 'nl'])->get('/');

    expect((string) $root->headers->get('Vary'))->toContain('Accept-Language');

    // A page whose language is written in its address answers the same
    // document to everyone: saying otherwise splits a cache for nothing.
    $feed = $this->withHeaders(['Accept-Language' => 'nl'])->get('/fr');

    expect((string) $feed->headers->get('Vary'))->not->toContain('Accept-Language');
});

<?php

declare(strict_types=1);

/**
 * Interface locale at arrival (D14).
 *
 * The service is translated into nine languages; opening in French for
 * everyone wastes eight of them, since a reader has to find a switch
 * written in a language they did not ask for before the site speaks
 * theirs.
 */
it('opens in the language of the browser', function (): void {
    $this->withHeaders(['Accept-Language' => 'es-ES,es;q=0.9,en;q=0.8'])
        ->get('/')
        ->assertOk()
        ->assertSee('Anuncios del ecosistema Dolibarr', escape: false)
        ->assertSee('lang="es"', escape: false);
});

it('reads a bare language tag as well as a regional one', function (): void {
    $this->withHeaders(['Accept-Language' => 'de'])
        ->get('/')
        ->assertOk()
        ->assertSee('lang="de"', escape: false);

    $this->withHeaders(['Accept-Language' => 'pt-BR,pt;q=0.9'])
        ->get('/')
        ->assertOk()
        ->assertSee('lang="pt"', escape: false);
});

it('honours the quality values rather than the first tag', function (): void {
    // A browser listing Japanese first but offering Italian next: the
    // offered language wins, not the position.
    $this->withHeaders(['Accept-Language' => 'ja;q=0.9,it;q=0.8'])
        ->get('/')
        ->assertOk()
        ->assertSee('lang="it"', escape: false);
});

it('falls back to the source language for a language it does not offer', function (): void {
    $this->withHeaders(['Accept-Language' => 'zh-CN,zh;q=0.9'])
        ->get('/')
        ->assertOk()
        ->assertSee('lang="fr"', escape: false);
});

it('opens in the source language without any header', function (): void {
    $this->get('/')->assertOk()->assertSee('lang="fr"', escape: false);
});

it('lets the switch override the browser for good', function (): void {
    // Someone who asks for a language means it, whatever their browser
    // says, and it must not flip back on the next page.
    $this->withHeaders(['Accept-Language' => 'es-ES'])->get('/locale/pl');

    $this->withHeaders(['Accept-Language' => 'es-ES'])
        ->get('/')
        ->assertOk()
        ->assertSee('lang="pl"', escape: false);
});

it('refuses a locale the service does not offer in the switch', function (): void {
    $this->withHeaders(['Accept-Language' => 'es-ES'])->get('/locale/ja');

    // The unknown choice is ignored, so negotiation still applies.
    $this->withHeaders(['Accept-Language' => 'es-ES'])
        ->get('/')
        ->assertOk()
        ->assertSee('lang="es"', escape: false);
});

it('tells caches that the answer depends on the header', function (): void {
    $response = $this->withHeaders(['Accept-Language' => 'nl'])->get('/');

    expect($response->headers->get('Vary'))->toContain('Accept-Language');
});

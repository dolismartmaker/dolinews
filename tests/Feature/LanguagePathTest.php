<?php

declare(strict_types=1);

use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Models\Project;
use App\Models\User;
use Tests\Support\Factory;

/**
 * The language of a page lives in its address (SPEC 6.5).
 *
 * What that buys, and what these tests hold to: a link opens in the
 * language it was shared in, and nine interface translations stop being
 * invisible to every search engine behind a single address.
 */
it('redirects the addresses issued before the language moved into the path', function (): void {
    $article = Factory::publishedArticle(User::factory()->create());

    $this->get('/revue')->assertRedirect(route('review.info'))->assertStatus(301);
    $this->get('/regles')->assertRedirect(route('pages.rules'))->assertStatus(301);
    $this->get('/articles/'.$article->getKey())
        ->assertRedirect(route('articles.show', ['article' => $article->getKey()]))
        ->assertStatus(301);

    $author = User::factory()->create();
    $editor = (new EditorService)->create($author, [
        'name' => 'ACME modules',
        'contact_email' => 'acme@example.test',
    ]);
    Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'module-xy',
        'name' => 'Module XY',
        'summary' => 'Gestion des XY',
    ]);

    $this->get('/projets/module-xy')
        ->assertRedirect(route('projects.show', ['slug' => 'module-xy']))
        ->assertStatus(301);
    $this->get('/editeurs/'.$editor->slug)
        ->assertRedirect(route('editors.show', ['slug' => $editor->slug]))
        ->assertStatus(301);
});

it('hands a reader the version of an announcement written in their language', function (): void {
    $author = User::factory()->create();
    $source = Factory::publishedArticle($author, ['title' => 'Sortie 4.0']);
    $translation = Factory::publishedTranslation($author, $source, 'es_ES');

    // Asked for in Spanish, answered in Spanish: the same fallback the
    // feed applies to a list (SPEC 6.1), on the address a reader is most
    // likely to be handed by someone else.
    $this->get(route('articles.show', ['locale' => 'es', 'article' => $source->getKey()]))
        ->assertRedirect(route('articles.show', ['locale' => 'es', 'article' => $translation->getKey()]));

    // And the other way round, because a French reader following a link
    // shared in Spanish deserves the same.
    $this->get(route('articles.show', ['locale' => 'fr', 'article' => $translation->getKey()]))
        ->assertRedirect(route('articles.show', ['locale' => 'fr', 'article' => $source->getKey()]));
});

it('serves an untranslated announcement under any language, naming one address for it', function (): void {
    $article = Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Sortie sans traduction',
        'locale' => 'fr_FR',
    ]);

    $french = route('articles.show', ['locale' => 'fr', 'article' => $article->getKey()]);

    // Nothing to redirect to, so the announcement is readable under the
    // Polish interface - and says the address of its one text.
    $html = (string) $this->get(route('articles.show', ['locale' => 'pl', 'article' => $article->getKey()]))
        ->assertOk()
        ->assertSee('Sortie sans traduction')
        ->getContent();

    expect($html)->toContain('<link rel="canonical" href="'.$french.'"');
});

it('declares every language version of a page that has ten', function (): void {
    $html = (string) $this->get('/fr/regles')->assertOk()->getContent();

    foreach ((array) config('dolinews.locales') as $locale) {
        expect($html)->toContain(
            'hreflang="'.$locale.'" href="'.route('pages.rules', ['locale' => $locale]).'"'
        );
    }

    // The address that names no language is the default one, which is
    // exactly what it does: it picks one.
    expect($html)->toContain('hreflang="x-default" href="'.route('root').'"');
});

it('keeps the reader where they are when they change language', function (): void {
    Factory::publishedArticle(User::factory()->create());

    $html = (string) $this->get('/fr?focus=security')->assertOk()->getContent();

    // The switch moves to the same page, filters kept: a reader who
    // narrowed the feed down and then asked for Spanish has not asked to
    // start over.
    expect($html)->toContain(route('home', ['locale' => 'es', 'focus' => 'security']));
});

it('maps every language of a page and every announcement at its own', function (): void {
    $author = User::factory()->create();
    $source = Factory::publishedArticle($author, ['title' => 'Sortie cartographiee']);
    $translation = Factory::publishedTranslation($author, $source, 'de_DE');

    $pages = (string) $this->get('/sitemap-pages.xml')->assertOk()->getContent();

    foreach ((array) config('dolinews.locales') as $locale) {
        expect($pages)->toContain(route('home', ['locale' => $locale]));
    }

    $articles = (string) $this->get('/sitemap-articles-1.xml')->assertOk()->getContent();

    // An announcement has one text: it is mapped under the language it
    // is written in, not under the ten the interface offers.
    expect($articles)
        ->toContain(route('articles.show', ['locale' => 'fr', 'article' => $source->getKey()]))
        ->toContain(route('articles.show', ['locale' => 'de', 'article' => $translation->getKey()]))
        ->not->toContain(route('articles.show', ['locale' => 'pl', 'article' => $source->getKey()]));
});

it('gives one announcement one address, whoever asks and in whatever language', function (): void {
    $article = Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Sortie identifiee une fois',
        'locale' => 'fr_FR',
    ]);

    $address = route('articles.show', ['locale' => 'fr', 'article' => $article->getKey()]);

    // A feed entry is identified by its address. Generated under the
    // locale of whoever asked, the same announcement would take a
    // different identity in each language, and a reader polling two of
    // them would be told about it twice.
    foreach (['fr', 'es', 'pl'] as $locale) {
        $feed = $this->getJson('/feeds.json?locale='.$locale)->assertOk();

        expect($feed->json('items.0.id'))->toBe($address)
            ->and($feed->json('items.0.url'))->toBe($address);
    }

    expect((string) $this->get('/feeds.xml?locale=es')->getContent())->toContain($address);
});

it('carries no language segment on what machines read', function (): void {
    // The feeds take their locale as a parameter and the map has no
    // language at all: prefixing either would have invented a tenth
    // address for one document.
    $this->get('/feeds.xml')->assertOk();
    $this->get('/feeds.json')->assertOk();
    $this->get('/sitemap.xml')->assertOk();
    $this->get('/robots.txt')->assertOk();
});

<?php

declare(strict_types=1);

use App\Domain\Dolinews\Models\Project;
use App\Models\User;
use Tests\Support\Factory;

/**
 * Free-text search (SPEC 6.1).
 *
 * The structured filters assume the reader knows a slug. A Dolibarr user
 * knows a function name, so the box searches the title, the summary and
 * the body, and answers with the matching sheets on top.
 */
it('finds an article by a word of its body', function (): void {
    Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Module XY 3.0',
        'summary' => 'Sortie de version.',
        'body' => 'Prise en charge de la facturation electronique Factur-X.',
    ]);

    Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Module ZZ 1.0',
        'summary' => 'Sortie de version.',
        'body' => 'Gestion des stocks.',
    ]);

    $this->get('/fr?q=Factur-X')
        ->assertOk()
        ->assertSee('Module XY 3.0')
        ->assertDontSee('Module ZZ 1.0');
});

it('ignores the case of the search terms', function (): void {
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module Caisse 2.4']);

    $this->get('/fr?q=caisse')->assertOk()->assertSee('Module Caisse 2.4');
});

it('ands the terms instead of widening the question', function (): void {
    $author = User::factory()->create();

    Factory::publishedArticle($author, [
        'title' => 'Module A',
        'summary' => 'Facturation electronique pour la v22.',
    ]);

    Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Module B',
        'summary' => 'Facturation papier.',
    ]);

    $this->get('/fr?q=facturation+electronique')
        ->assertOk()
        ->assertSee('Module A')
        ->assertDontSee('Module B');
});

it('treats the wildcards of LIKE as ordinary characters', function (): void {
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module mod_paie 1.0']);
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module modXpaie 1.0']);

    $this->get('/fr?q=mod_paie')
        ->assertOk()
        ->assertSee('Module mod_paie 1.0')
        ->assertDontSee('Module modXpaie 1.0');
});

it('lists the matching sheets above the feed', function (): void {
    [$user, $editor] = Factory::contributorWithEditor();

    Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'caisse-tactile',
        'name' => 'Caisse tactile',
        'summary' => 'Point de vente pour Dolibarr.',
        'status' => 'active',
    ]);

    $this->get('/fr?q=caisse')
        ->assertOk()
        ->assertSee('Fiches correspondantes')
        ->assertSee('Caisse tactile');
});

it('finds a sheet by the name of its editor', function (): void {
    [$user, $editor] = Factory::contributorWithEditor();

    $editor->forceFill(['name' => 'Editions Rousseau'])->save();

    Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'module-rousseau',
        'name' => 'Module de paie',
        'summary' => 'Paie francaise.',
        'status' => 'active',
    ]);

    $this->get('/fr?q=Rousseau')->assertOk()->assertSee('Module de paie');
});

it('says why an empty search is empty', function (): void {
    // A bare "no result" reads as "the service is empty", which is not
    // what happened: this editor does not publish here yet.
    $this->get('/fr?q=introuvable')
        ->assertOk()
        ->assertSee('Aucune annonce ne correspond à cette recherche.', escape: false)
        ->assertSee('Vous êtes cet éditeur ?', escape: false);
});

it('carries the search into the generic feeds', function (): void {
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module Factur 1.0']);
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module Stock 1.0']);

    $rss = $this->get('/feeds.xml?q=factur');

    $rss->assertOk();

    expect($rss->getContent())->toContain('Module Factur 1.0')
        ->and($rss->getContent())->not->toContain('Module Stock 1.0');
});

it('offers the same filter on the API', function (): void {
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module Api Factur 1.0']);
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module Api Stock 1.0']);

    $payload = $this->getJson('/api/v1/articles?q=factur')->assertOk()->json('data');

    expect($payload)->toHaveCount(1)
        ->and($payload[0]['title'])->toBe('Module Api Factur 1.0');
});

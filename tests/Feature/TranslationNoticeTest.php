<?php

declare(strict_types=1);

use App\Domain\Dolinews\Seo\ArticleUrl;
use App\Models\User;
use Tests\Support\Factory;

/**
 * A language version says it is one, and leads to its original
 * (SPEC 5.7) - without saying who translated it.
 */
it('tells the reader of a translation where the original is', function (): void {
    $author = User::factory()->create();
    $source = Factory::publishedArticle($author, ['title' => 'Sortie 4.0', 'locale' => 'fr_FR']);
    $translation = Factory::publishedTranslation($author, $source, 'es_ES');

    $this->get(route('articles.show', ['locale' => 'es', 'article' => $translation->getKey()]))
        ->assertOk()
        ->assertSee('Este anuncio es una traducción.')
        ->assertSee(ArticleUrl::for($source), false)
        ->assertSee('<span lang="fr-FR">Français</span>', false);

    // The original is not a translation of anything.
    $this->get(route('articles.show', ['locale' => 'fr', 'article' => $source->getKey()]))
        ->assertOk()
        ->assertDontSee('Cette annonce est une traduction.')
        ->assertDontSee('Lire la version d\'origine');
});

it('raises the notice to a warning when the original has changed since', function (): void {
    $author = User::factory()->create();
    $source = Factory::publishedArticle($author, ['title' => 'Sortie 4.0', 'locale' => 'fr_FR']);
    $translation = Factory::publishedTranslation($author, $source, 'es_ES');

    // A revision applied to the source after the translation was written.
    $source->forceFill(['revision_number' => $source->revision_number + 1])->save();

    $this->get(route('articles.show', ['locale' => 'es', 'article' => $translation->getKey()]))
        ->assertOk()
        ->assertSee('Esta traducción se hizo a partir de una versión anterior del anuncio.')
        ->assertDontSee('Este anuncio es una traducción.')
        ->assertSee(ArticleUrl::for($source), false);
});

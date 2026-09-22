<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\TranslationMandateService;
use App\Domain\Dolinews\Articles\TranslationService;
use Tests\Support\Factory;

/**
 * The author's workspace lists announcements, not rows of `articles`
 * (SPEC 5.3: the unit is the translation group).
 *
 * An announcement declined into ten languages is one piece of work; its
 * ten versions listed side by side bury everything else. What the line
 * shows is what the account wrote in the group, which is what makes the
 * screen usable by a mandated translator too (SPEC 5.6).
 */
it('lists one line per announcement, not one per language version', function (): void {
    [$author] = Factory::contributorWithEditor();
    $source = Factory::publishedArticle($author, ['title' => 'Annonce source']);
    Factory::publishedTranslation($author, $source, 'en_US', ['title' => 'Version anglaise']);

    $this->actingAs($author->refresh())
        ->get('/account/articles')
        ->assertOk()
        ->assertSee('Annonce source')
        ->assertDontSee('Version anglaise')
        // The other languages are counted on the line, not listed as rows.
        ->assertSee('+1');
});

it('keeps the workspace of a mandated translator filled with its own work', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $source = Factory::publishedArticle($author, ['title' => 'Annonce source']);

    $translator = Factory::contributorWithoutEditor();
    app(TranslationMandateService::class)->grant($editor, $author, $translator);

    app(TranslationService::class)->submitTranslation($source, $translator, 'es_ES', [
        'title' => 'Version espagnole',
        'summary' => 'Resume traduit.',
        'body' => '## Details',
    ]);

    // It does not own the source it translates: keeping only sources
    // would empty its workspace of everything it has in hand.
    $this->actingAs($translator->refresh())
        ->get('/account/articles')
        ->assertOk()
        ->assertSee('Version espagnole');

    // And that version is not a line of its own for the author of the
    // source, who sees the announcement once.
    $this->actingAs($author->refresh())
        ->get('/account/articles')
        ->assertOk()
        ->assertSee('Annonce source')
        ->assertDontSee('Version espagnole');
});

it('raises an announcement translated today above a newer one', function (): void {
    [$author] = Factory::contributorWithEditor();
    $old = Factory::publishedArticle($author, ['title' => 'Annonce ancienne']);

    $this->travel(2)->days();
    Factory::publishedArticle($author, ['title' => 'Annonce recente']);

    $this->travel(1)->days();
    Factory::publishedTranslation($author, $old, 'en_US', ['title' => 'Version anglaise']);

    $html = $this->actingAs($author->refresh())
        ->get('/account/articles')
        ->assertOk()
        ->getContent();

    // Ordered on the latest activity of the group: the work in progress
    // is not at the bottom of the list.
    expect(strpos((string) $html, 'Annonce ancienne'))
        ->toBeLessThan(strpos((string) $html, 'Annonce recente'));
});

it('shows a draft that has no language version yet', function (): void {
    [$author] = Factory::contributorWithEditor();
    Factory::article($author, ['title' => 'Brouillon en cours']);

    $this->actingAs($author->refresh())
        ->get('/account/articles')
        ->assertOk()
        ->assertSee('Brouillon en cours')
        ->assertDontSee('+1');
});

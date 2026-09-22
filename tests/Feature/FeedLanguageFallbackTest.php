<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Articles\TranslationService;
use App\Domain\Dolinews\Enums\ReviewDecision;
use App\Domain\Dolinews\Review\ReviewService;
use App\Models\User;
use Tests\Support\Factory;

/**
 * The feed filtered by language (SPEC 6.1, settled 2026-09-22).
 *
 * An announcement with no version in the reader's language is shown in
 * its source language, flagged as such. Hiding it penalised the
 * untranslated announcement in distribution - which SPEC 6.1 forbids -
 * and showed a reader whose language is young here an empty service.
 */
function publishTranslation(User $author, $source, string $locale, string $title)
{
    $translation = app(TranslationService::class)->submitTranslation($source, $author, $locale, [
        'title' => $title,
        'summary' => 'Resume traduit.',
        'body' => '## Corps',
    ]);

    app(ArticleService::class)->submit($translation, $author);

    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        app(ReviewService::class)->postMessage($translation, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    return $translation->refresh();
}

it('shows an untranslated announcement in its source language', function (): void {
    Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Sortie non traduite',
        'locale' => 'fr_FR',
    ]);

    $this->withHeaders(['Accept-Language' => 'es'])
        ->get('/')
        ->assertOk()
        ->assertSee('Sortie non traduite');
});

it('flags the language of an announcement shown for want of a translation', function (): void {
    Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Sortie non traduite',
        'locale' => 'fr_FR',
    ]);

    $response = $this->withHeaders(['Accept-Language' => 'es'])->get('/');

    $response->assertOk();

    // The endonym also appears twice in the header language switch, so
    // the badge itself is what is looked for, not the word.
    $response->assertSee('badge">en Français', escape: false);
});

it('prefers the translation when the group has one', function (): void {
    $author = User::factory()->create();

    $source = Factory::publishedArticle($author, [
        'title' => 'Version francaise',
        'locale' => 'fr_FR',
    ]);

    publishTranslation($author, $source, 'es_ES', 'Version espagnole');

    $response = $this->withHeaders(['Accept-Language' => 'es'])->get('/');

    $response->assertOk()
        ->assertSee('Version espagnole')
        ->assertDontSee('Version francaise');
});

it('never shows the same announcement twice', function (): void {
    $author = User::factory()->create();

    $source = Factory::publishedArticle($author, [
        'title' => 'Version francaise unique',
        'locale' => 'fr_FR',
    ]);

    publishTranslation($author, $source, 'en_US', 'English only version');

    // A reader in a third language gets the source, once.
    $response = $this->withHeaders(['Accept-Language' => 'de'])->get('/');

    $response->assertOk()->assertSee('Version francaise unique');

    expect(substr_count((string) $response->getContent(), 'Version francaise unique'))->toBe(1);
});

it('carries no language badge when the announcement is in the reader language', function (): void {
    Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Sortie francaise',
        'locale' => 'fr_FR',
    ]);

    $response = $this->withHeaders(['Accept-Language' => 'fr'])->get('/');

    $response->assertOk()->assertSee('Sortie francaise');

    // Only the header language switch names the language: no badge.
    $response->assertDontSee('badge">en Français', escape: false);
});

it('keeps the strict language filter on the API', function (): void {
    // The API contract is frozen (D12): a client asking for one locale
    // gets that locale, or nothing.
    Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Sortie francaise seule',
        'locale' => 'fr_FR',
    ]);

    $payload = $this->getJson('/api/v1/articles?locale=es_ES')->assertOk()->json('data');

    expect($payload)->toBe([]);
});

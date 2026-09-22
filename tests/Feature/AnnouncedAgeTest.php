<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Tests\Support\Factory;

/**
 * The age a maturity badge always carries (SPEC 6.3).
 *
 * Counted in months alone, everything published this month - that is,
 * the whole head of a young feed - read "annoncée il y a 0 mois". A
 * count of zero reads as a bug, and it wore it on the freshest
 * announcements. The unit follows the distance instead.
 */
it('says today rather than zero months', function (): void {
    $article = Factory::publishedArticle(User::factory()->create());

    expect($article->announcedAge())->toBe('annoncée aujourd\'hui');
});

it('counts in days, then months, then years', function (): void {
    $article = Factory::publishedArticle(User::factory()->create());

    $cases = [
        1 => 'annoncée hier',
        3 => 'annoncée il y a 3 jours',
        40 => 'annoncée il y a 1 mois',
        200 => 'annoncée il y a 6 mois',
        800 => 'annoncée il y a 2 ans',
    ];

    foreach ($cases as $daysAgo => $expected) {
        $article->forceFill(['published_at' => Carbon::now()->subDays($daysAgo)])->save();

        expect($article->announcedAge())->toBe($expected, "il y a {$daysAgo} jours");
    }
});

it('speaks the language of the reader', function (): void {
    $article = Factory::publishedArticle(User::factory()->create());
    $article->forceFill(['published_at' => Carbon::now()->subDays(3)])->save();

    app()->setLocale('de');

    expect($article->announcedAge())->toBe('vor 3 Tagen angekündigt');

    app()->setLocale('fr');
});

it('paginates in French rather than in English', function (): void {
    // Laravel's own view reads "Showing 1 to 25 of 38 results", built
    // from four bare keys - Showing, to, of, results - that French, as
    // the source language, has no file to answer. Rendered here rather
    // than through a feed of twenty-six published articles: the view is
    // what changed, and the quorum of three costs half a minute.
    $paginator = new LengthAwarePaginator(range(1, 25), 38, 25, 1, ['path' => '/fr']);

    $html = (string) $paginator->links();

    expect($html)->toContain('Annonces 1 à 25 sur 38')
        ->and($html)->not->toContain('Showing')
        ->and($html)->not->toContain('results');
});

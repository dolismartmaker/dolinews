<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Review\ReviewService;
use App\Models\User;
use Tests\Support\Factory;

/**
 * Order of the public feed (SPEC 6.1): the date the announcement bears,
 * newest first, whatever the day the review accepted it.
 *
 * A back-dated catalogue makes the two dates diverge by years, and that
 * is precisely when the order has to follow published_at rather than
 * anything the circuit left behind.
 */
it('lists announcements newest first, back-dated ones included', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $articles = app(ArticleService::class);
    $review = app(ReviewService::class);

    // Published in this order, dated in another: the feed must follow
    // the dates, not the sequence of acceptances.
    $dates = [
        'Annonce de 2024' => '2024-04-25',
        'Annonce de 2026' => '2026-03-23',
        'Annonce de 2025' => '2025-11-14',
    ];

    foreach ($dates as $title => $date) {
        $article = Factory::article($admin, ['title' => $title]);
        $articles->submit($article, $admin);
        $review->publishByAdmin($article, $admin, 'amorçage');
        $review->redatePublication($article, $admin, 'archives', now()->parse($date));
    }

    $response = $this->get('/');

    $response->assertOk();

    $html = $response->getContent();
    $positions = [];

    foreach (array_keys($dates) as $title) {
        $positions[$title] = strpos($html, $title);
        expect($positions[$title])->not->toBeFalse("« {$title} » absent du fil");
    }

    expect($positions['Annonce de 2026'])->toBeLessThan($positions['Annonce de 2025'])
        ->and($positions['Annonce de 2025'])->toBeLessThan($positions['Annonce de 2024']);
});

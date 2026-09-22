<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\ModerationAction;
use App\Domain\Dolinews\Models\ModerationLog;
use App\Domain\Dolinews\Review\BootstrapPhaseService;
use App\Domain\Dolinews\Review\ReviewException;
use App\Domain\Dolinews\Review\ReviewService;
use App\Models\User;
use Tests\Support\Factory;

/**
 * Correction of an already published date (SPEC 5.1).
 *
 * publishByAdmin only back-dates at the moment of publication, which
 * left the archives submitted before it existed carrying the date the
 * review accepted them. The correction is a journalled act, never a raw
 * UPDATE: a date nobody can read in the journal is a date nobody can
 * contest (SPEC 9.4).
 */
it('moves a published article back to the date of its version', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);
    app(ArticleService::class)->submit($article, $admin);
    app(ReviewService::class)->publishByAdmin($article, $admin, 'amorçage');

    expect($article->refresh()->isBackdated())->toBeFalse();

    app(ReviewService::class)->redatePublication(
        $article,
        $admin,
        'rattrapage des archives',
        now()->subYears(3)->startOfDay(),
    );

    $article = $article->refresh();

    expect($article->status)->toBe(ArticleStatus::PUBLISHED)
        ->and($article->published_at?->format('Y-m-d'))
        ->toBe(now()->subYears(3)->format('Y-m-d'))
        ->and($article->isBackdated())->toBeTrue();
});

it('records the correction and both dates in the moderation journal', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);
    app(ArticleService::class)->submit($article, $admin);
    app(ReviewService::class)->publishByAdmin($article, $admin, 'amorçage');

    $was = $article->refresh()->published_at?->format('Y-m-d');

    app(ReviewService::class)->redatePublication(
        $article,
        $admin,
        'rattrapage des archives',
        now()->subYears(3)->startOfDay(),
    );

    /** @var ModerationLog $entry */
    $entry = ModerationLog::query()
        ->where('article_id', $article->getKey())
        ->orderByDesc('id')
        ->firstOrFail();

    expect($entry->action)->toBe(ModerationAction::PUBLISHED_BY_ADMIN)
        ->and($entry->motive)->toContain('rattrapage des archives')
        ->and($entry->motive)->toContain((string) $was)
        ->and($entry->motive)->toContain(now()->subYears(3)->format('Y-m-d'));
});

it('refuses a corrected date in the future', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);
    app(ArticleService::class)->submit($article, $admin);
    app(ReviewService::class)->publishByAdmin($article, $admin, 'amorçage');

    app(ReviewService::class)->redatePublication($article, $admin, 'motif', now()->addDay());
})->throws(ReviewException::class);

it('refuses to correct the date of an article still in review', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);
    app(ArticleService::class)->submit($article, $admin);

    app(ReviewService::class)->redatePublication($article, $admin, 'motif', now()->subYear());
})->throws(ReviewException::class);

it('refuses the correction to anyone but the super admin', function (): void {
    $moderator = User::factory()->moderator()->create();
    $author = User::factory()->create();
    $article = Factory::publishedArticle($author);

    app(ReviewService::class)->redatePublication($article, $moderator, 'motif', now()->subYear());
})->throws(ReviewException::class);

it('closes the correction on the admin own content outside the bootstrap phase', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);
    app(ArticleService::class)->submit($article, $admin);
    app(ReviewService::class)->publishByAdmin($article, $admin, 'amorçage');

    // The phase closes between the publication and the correction, the
    // team reaching the floor of six moderators (SPEC 9.1): the power
    // that was open during the bootstrap is not open afterwards.
    User::factory()->count(6)->moderator()->create();
    expect(app(BootstrapPhaseService::class)->isOpen())->toBeFalse();

    app(ReviewService::class)->redatePublication($article, $admin, 'motif', now()->subYear());
})->throws(ReviewException::class);

it('never spends a second bootstrap publication on a correction', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);
    app(ArticleService::class)->submit($article, $admin);
    app(ReviewService::class)->publishByAdmin($article, $admin, 'amorçage');

    $before = app(BootstrapPhaseService::class)->bootstrapPublications();

    app(ReviewService::class)->redatePublication($article, $admin, 'motif', now()->subYears(2));

    // The article was already published, and already counted. Counting it
    // twice would close the phase on publications that never happened.
    expect(app(BootstrapPhaseService::class)->bootstrapPublications())->toBe($before);
});

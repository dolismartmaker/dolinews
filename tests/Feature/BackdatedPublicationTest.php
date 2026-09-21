<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Articles\PublicationQuotaService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Review\ReviewException;
use App\Domain\Dolinews\Review\ReviewService;
use App\Domain\Dolinews\Review\ReviewStats;
use App\Models\User;
use Tests\Support\Factory;

/**
 * Back-dated publication (SPEC 5.1): the super admin carries into the
 * feed a version released before the service existed.
 *
 * Three things hang on detecting it, and each would misreport on its
 * own: the public review delay, the publication token bucket, and the
 * durable mention the article bears.
 */
it('back-dates a publication to the date of the version it announces', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);
    app(ArticleService::class)->submit($article, $admin);

    $released = now()->subYears(2)->startOfDay();

    app(ReviewService::class)->publishByAdmin($article, $admin, 'catalogue', $released);

    $article = $article->refresh();

    expect($article->status)->toBe(ArticleStatus::PUBLISHED)
        ->and($article->published_at?->format('Y-m-d'))->toBe($released->format('Y-m-d'))
        ->and($article->isBackdated())->toBeTrue();
});

it('records the back-dating in the moderation journal', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);
    app(ArticleService::class)->submit($article, $admin);

    app(ReviewService::class)->publishByAdmin($article, $admin, 'catalogue', now()->subYear());

    // The journal is what an author reads to contest an act (SPEC 9.4):
    // a date absent from it is a date nobody can contest.
    $motive = (string) DB::table('moderation_log')
        ->where('article_id', $article->getKey())
        ->value('motive');

    expect($motive)->toContain('catalogue')
        ->and($motive)->toContain('antidatée');
});

it('refuses a publication date in the future', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);
    app(ArticleService::class)->submit($article, $admin);

    expect(fn () => app(ReviewService::class)
        ->publishByAdmin($article, $admin, 'catalogue', now()->addWeek()))
        ->toThrow(ReviewException::class);

    expect($article->refresh()->status)->toBe(ArticleStatus::PENDING);
});

it('leaves an ordinary publication undated as back-dated', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);
    app(ArticleService::class)->submit($article, $admin);

    app(ReviewService::class)->publishByAdmin($article, $admin, 'correctif urgent');

    expect($article->refresh()->isBackdated())->toBeFalse();
});

it('keeps the back-dated articles out of the observed review delay', function (): void {
    $admin = User::factory()->superAdmin()->create();

    // One ordinary publication: submitted now, published now, delay ~0.
    $ordinary = Factory::article($admin, ['title' => 'Module XY 2.2']);
    app(ArticleService::class)->submit($ordinary, $admin);
    app(ReviewService::class)->publishByAdmin($ordinary, $admin, 'correctif');

    $withoutBackdated = app(ReviewStats::class)->observedMedianSeconds();

    // One back-dated publication: its published_at precedes its own
    // submission, so its "delay" is negative and measures nothing.
    $backdated = Factory::article($admin, ['title' => 'Module XY 1.0']);
    app(ArticleService::class)->submit($backdated, $admin);
    app(ReviewService::class)->publishByAdmin($backdated, $admin, 'catalogue', now()->subYears(3));

    expect(app(ReviewStats::class)->observedMedianSeconds())->toBe($withoutBackdated)
        ->and(app(ReviewStats::class)->observedMedianSeconds())->toBeGreaterThanOrEqual(0.0);
});

it('never spends a publication token on a back-dated article', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);
    app(ArticleService::class)->submit($article, $admin);

    $before = app(PublicationQuotaService::class)->availableTokensFor($article);

    app(ReviewService::class)->publishByAdmin($article, $admin, 'catalogue', now()->subYears(4));

    // Its date precedes the bucket's own start, so the bucket maths would
    // spend a token without ever accruing the time that earns one back:
    // a handful of them would empty a bucket they never drew on.
    expect(app(PublicationQuotaService::class)->availableTokensFor($article->refresh()))
        ->toBeGreaterThan($before);
});

it('scopes back-dated articles both ways', function (): void {
    $admin = User::factory()->superAdmin()->create();

    $ordinary = Factory::article($admin, ['title' => 'Module XY 3.0']);
    app(ArticleService::class)->submit($ordinary, $admin);
    app(ReviewService::class)->publishByAdmin($ordinary, $admin, 'correctif');

    $backdated = Factory::article($admin, ['title' => 'Module XY 0.9']);
    app(ArticleService::class)->submit($backdated, $admin);
    app(ReviewService::class)->publishByAdmin($backdated, $admin, 'catalogue', now()->subYears(5));

    expect(Article::query()->backdated()->pluck('id')->all())
        ->toBe([$backdated->getKey()])
        ->and(Article::query()->notBackdated()->pluck('id')->all())
        ->toBe([$ordinary->getKey()]);
});

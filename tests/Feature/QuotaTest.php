<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Articles\QuotaException;
use App\Domain\Dolinews\Articles\TranslationService;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\ReviewDecision;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Moderation\ModerationService;
use App\Domain\Dolinews\Review\ReviewService;
use App\Models\User;

/**
 * Publication quotas (SPEC 5.3): the token bucket and the queue
 * ceiling. The bucket starts full (capacity 3 by default in tests),
 * accrues one token every N days, a pending submission reserves one, a
 * refusal returns it, a published article keeps consuming whatever its
 * later status. Both limits count announcements, not article rows: the
 * language versions of one announcement share a single queue slot.
 */
function quotaArticle(User $author, array $overrides = []): Article
{
    static $editor = null;

    if ($editor === null || $author->editors()->where('editors.id', $editor->getKey())->doesntExist()) {
        $editor = (new EditorService)->create($author, [
            'name' => 'Editeur quota',
            'contact_email' => 'quota@editeur.test',
        ]);
    }

    return app(ArticleService::class)->createDraft($author, $editor, array_merge([
        'type' => 'release',
        'title' => 'Release '.uniqid(),
        'summary' => 'Resume',
        'body' => 'Corps',
        'locale' => 'fr_FR',
        'maturity' => 'stable',
        'compat_status' => 'declared',
    ], $overrides));
}

it('reserves a token per pending submission', function (): void {
    $author = User::factory()->create();

    for ($i = 0; $i < 3; $i++) {
        $article = quotaArticle($author);
        app(ArticleService::class)->submit($article, $author);
    }

    // Capacity 3, all reserved by pending submissions: the next one is
    // refused (checking only at acceptance would let fifteen in at once).
    $fourth = quotaArticle($author);

    app(ArticleService::class)->submit($fourth, $author);
})->throws(QuotaException::class);

it('returns the token on refusal', function (): void {
    $author = User::factory()->create();
    $review = app(ReviewService::class);

    for ($i = 0; $i < 3; $i++) {
        $article = quotaArticle($author);
        app(ArticleService::class)->submit($article, $author);
        $review->postMessage($article, User::factory()->moderator()->create(), 'hors sujet', ReviewDecision::REJECTED);
    }

    // Three refusals later, the bucket is full again.
    $again = quotaArticle($author);
    app(ArticleService::class)->submit($again, $author);

    expect($again->refresh()->status)->toBe(ArticleStatus::PENDING);
});

it('keeps a hidden article consuming its token', function (): void {
    $author = User::factory()->create();
    $review = app(ReviewService::class);
    $moderation = app(ModerationService::class);

    for ($i = 0; $i < 3; $i++) {
        $article = quotaArticle($author);
        app(ArticleService::class)->submit($article, $author);

        // Publish on quorum then hide: the token stays consumed.
        foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
            $review->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);
        }

        $moderation->hideArticle($article->refresh(), User::factory()->moderator()->create(), 'contenu hors charte R1');
    }

    $next = quotaArticle($author);

    app(ArticleService::class)->submit($next, $author);
})->throws(QuotaException::class);

it('never charges a translation a token', function (): void {
    $author = User::factory()->create();
    $review = app(ReviewService::class);

    // Fill the bucket with three published releases.
    for ($i = 0; $i < 3; $i++) {
        $article = quotaArticle($author);
        app(ArticleService::class)->submit($article, $author);

        foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
            $review->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);
        }
    }

    $source = $article;
    $translation = app(TranslationService::class)->submitTranslation($source->refresh(), $author, 'en_US', [
        'title' => 'Module XY 2.1',
        'summary' => 'Security fix and v22 compat.',
        'body' => 'Details',
    ]);

    // The bucket is empty, the translation still submits: D14.
    app(ArticleService::class)->submit($translation, $author);

    expect($translation->refresh()->status)->toBe(ArticleStatus::PENDING);
});

it('holds one queue slot for an announcement whatever its languages', function (): void {
    config()->set('dolinews.quota.queue_ceiling', 1);

    $author = User::factory()->create();

    $source = quotaArticle($author);
    app(ArticleService::class)->submit($source, $author);

    // The ceiling is one and the source already holds it: its language
    // versions join the slot their announcement paid for (SPEC 5.3).
    foreach (['en_US', 'es_ES', 'de_DE'] as $locale) {
        $translation = app(TranslationService::class)->submitTranslation($source->refresh(), $author, $locale, [
            'title' => 'T '.$locale,
            'summary' => 'S',
            'body' => 'B',
        ]);
        app(ArticleService::class)->submit($translation, $author);

        expect($translation->refresh()->status)->toBe(ArticleStatus::PENDING);
    }

    // A second announcement, however, finds the ceiling full.
    $other = quotaArticle($author);

    app(ArticleService::class)->submit($other, $author);
})->throws(QuotaException::class);

it('counts a translation as a slot when its announcement holds none', function (): void {
    config()->set('dolinews.quota.queue_ceiling', 1);

    $author = User::factory()->create();
    $review = app(ReviewService::class);

    // One published source: its group is out of the queue.
    $source = quotaArticle($author);
    app(ArticleService::class)->submit($source, $author);
    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        $review->postMessage($source, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    $translation = app(TranslationService::class)->submitTranslation($source->refresh(), $author, 'en_US', [
        'title' => 'T',
        'summary' => 'S',
        'body' => 'B',
    ]);
    app(ArticleService::class)->submit($translation, $author);

    // The translation now occupies the only slot: a reviewer is busy.
    $pending = quotaArticle($author);

    app(ArticleService::class)->submit($pending, $author);
})->throws(QuotaException::class);

it('lets an author resubmit a pending article on a full queue', function (): void {
    config()->set('dolinews.quota.queue_ceiling', 1);

    $author = User::factory()->create();

    $article = quotaArticle($author);
    app(ArticleService::class)->submit($article, $author);

    // The ceiling is full of this very article: correcting it must not
    // be refused, or the author is locked out of their own review.
    app(ArticleService::class)->submit($article->refresh(), $author);

    expect($article->refresh()->status)->toBe(ArticleStatus::PENDING);
});

it('bills project-less announcements to the editor bucket', function (): void {
    config()->set('dolinews.quota.bucket_capacity', 1);

    $author = User::factory()->create();

    $one = quotaArticle($author, ['type' => 'announcement', 'project_id' => null]);
    app(ArticleService::class)->submit($one, $author);

    $two = quotaArticle($author, ['type' => 'announcement', 'project_id' => null]);

    app(ArticleService::class)->submit($two, $author);
})->throws(QuotaException::class);

it('accrues tokens back over time', function (): void {
    config()->set('dolinews.quota.bucket_capacity', 1);
    config()->set('dolinews.quota.token_days', 7);

    $author = User::factory()->create();
    $review = app(ReviewService::class);

    $article = quotaArticle($author);
    app(ArticleService::class)->submit($article, $author);
    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        $review->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    // Bucket empty right now.
    $blocked = quotaArticle($author);
    try {
        app(ArticleService::class)->submit($blocked, $author);
        $this->fail('submission should have been refused');
    } catch (QuotaException) {
    }

    // One accrual period later, one token is back.
    $this->travelTo(now()->addDays(8));

    $later = quotaArticle($author);
    app(ArticleService::class)->submit($later, $author);

    expect($later->refresh()->status)->toBe(ArticleStatus::PENDING);
});

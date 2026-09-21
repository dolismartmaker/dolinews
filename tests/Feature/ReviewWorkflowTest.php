<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\PublicationMode;
use App\Domain\Dolinews\Enums\ReviewDecision;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\ServiceState;
use App\Domain\Dolinews\Review\BootstrapPhaseService;
use App\Domain\Dolinews\Review\ReviewException;
use App\Domain\Dolinews\Review\ReviewService;
use App\Models\User;
use Tests\Support\Factory;

/**
 * The a-priori review circuit (SPEC 5.1): quorum of three, accords
 * reset on resubmission, super-admin override and bootstrap bounds.
 */
it('publishes automatically when three moderators accept', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    $review = app(ReviewService::class);

    foreach (User::factory()->count(2)->moderator()->create() as $moderator) {
        $review->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    expect($article->refresh()->status)->toBe(ArticleStatus::PENDING);

    $third = User::factory()->moderator()->create();
    $review->postMessage($article, $third, 'accord', ReviewDecision::ACCEPTED);

    $article = $article->refresh();

    expect($article->status)->toBe(ArticleStatus::PUBLISHED)
        ->and($article->publication_mode)->toBe(PublicationMode::QUORUM)
        ->and($article->published_at)->not->toBeNull();
});

it('never counts the author in the quorum, even as a moderator', function (): void {
    $author = User::factory()->moderator()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    $review = app(ReviewService::class);

    // The author accepts their own article, twice over: it never counts.
    $review->postMessage($article, $author, 'accord', ReviewDecision::ACCEPTED);

    foreach (User::factory()->count(2)->moderator()->create() as $moderator) {
        $review->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    expect($article->refresh()->status)->toBe(ArticleStatus::PENDING);
});

it('resets accords on resubmission', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    $service = app(ArticleService::class);
    $service->submit($article, $author);

    $review = app(ReviewService::class);
    $moderator = User::factory()->moderator()->create();
    $review->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);

    // Rejection sends the article back; resubmission moves submitted_at.
    $review->postMessage($article, User::factory()->moderator()->create(), 'hors sujet', ReviewDecision::REJECTED);
    expect($article->refresh()->status)->toBe(ArticleStatus::REJECTED);

    $service->submit($article, $author);

    expect(count($review->currentAccords($article->refresh())))->toBe(0)
        // The message trace stays: the effect is cancelled, not the record.
        ->and($article->reviewMessages()->count())->toBe(2);
});

it('treats an author edit while pending as a resubmission', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    $service = app(ArticleService::class);
    $service->submit($article, $author);

    $review = app(ReviewService::class);
    $moderator = User::factory()->moderator()->create();
    $review->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);

    $service->edit($article->refresh(), $author, ['title' => 'Module XY 2.1 (corrige)']);

    expect(count($review->currentAccords($article->refresh())))->toBe(0);
});

it('orders the review queue security first, then oldest', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    $editors = new EditorService;
    $editor = $article->editor;

    $plain = app(ArticleService::class)->createDraft($author, $editor, [
        'type' => 'release',
        'title' => 'Release ordinaire',
        'summary' => 'Fonctionnalite mineure.',
        'body' => 'Corps',
        'locale' => 'fr_FR',
        'maturity' => 'stable',
        'compat_status' => 'declared',
    ]);

    // Two submissions, security one submitted last: it still comes first.
    app(ArticleService::class)->submit($plain, $author);
    $this->travelTo(now()->addHours(1));
    app(ArticleService::class)->submit($article, $author);

    $queue = Article::query()->reviewQueue()->get();

    expect($queue->first()->getKey())->toBe($article->getKey())
        ->and($queue->last()->getKey())->toBe($plain->getKey());
});

it('refuses the quorum override on the admin own content outside bootstrap', function (): void {
    // Close the bootstrap phase first: a third-party submission closes it.
    $admin = User::factory()->superAdmin()->create();
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    app(ReviewService::class)->publishByAdmin(
        $article,
        $admin,
        'correctif de securite d\'un tiers coincé dans une file vide',
    );

    // Now the admin's own article, with the phase closed.
    $own = Factory::article($admin);
    app(ArticleService::class)->submit($own, $admin);

    app(ReviewService::class)->publishByAdmin($own, $admin, 'test');
})->throws(ReviewException::class);

it('allows the override for a third party, competitor included', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    app(ReviewService::class)->publishByAdmin($article, $admin, 'correctif de securite concurrent, file vide un dimanche');

    expect($article->refresh()->status)->toBe(ArticleStatus::PUBLISHED);

    // The override is journalled with its motive (SPEC 5.1/9.4).
    $this->assertDatabaseHas('moderation_log', [
        'action' => 'published_by_admin',
        'article_id' => $article->getKey(),
    ]);
});

it('bounds the bootstrap phase at ten publications', function (): void {
    config()->set('dolinews.review.bootstrap_ceiling', 3);

    $admin = User::factory()->superAdmin()->create();
    $bootstrap = new BootstrapPhaseService;

    for ($i = 0; $i < 3; $i++) {
        expect($bootstrap->isOpen())->toBeTrue();

        $article = Factory::article($admin);
        app(ReviewService::class)->publishByAdmin($article, $admin, 'amorçage '.$i);
    }

    expect($bootstrap->isOpen())->toBeFalse()
        ->and(ServiceState::read('bootstrap_phase_closed_at'))->not->toBeNull();
});

it('closes the bootstrap phase at the moderator floor and never reopens', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $bootstrap = new BootstrapPhaseService;

    User::factory()->count(6)->moderator()->create();

    expect($bootstrap->isOpen())->toBeFalse();

    // Even if the team drops under the floor, the phase stays closed.
    User::query()->where('is_moderator', true)->update(['active' => false]);

    expect($bootstrap->isOpen())->toBeFalse();
});

it('closes the bootstrap phase at the first third-party submission', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $bootstrap = new BootstrapPhaseService;

    expect($bootstrap->isOpen())->toBeTrue();

    $thirdParty = User::factory()->create();
    $article = Factory::article($thirdParty);
    app(ArticleService::class)->submit($article, $thirdParty);

    expect($bootstrap->isOpen())->toBeFalse();
});

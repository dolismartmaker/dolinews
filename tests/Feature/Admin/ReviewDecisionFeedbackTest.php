<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\ReviewDecision;
use App\Domain\Dolinews\Review\ReviewService;
use App\Livewire\Admin\ReviewShow;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * What a moderator gets back after acting on a review screen.
 *
 * A decision used to leave the page looking untouched, so the same
 * accord was posted twice. The quorum itself never counted it twice
 * (accords are unique per moderator), but nothing said so.
 */
it('sends the moderator back to the queue with the accord count', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ReviewShow::class, ['article' => $article])
        ->set('messageBody', 'relecture faite, rien a redire')
        ->set('decision', 'accepted')
        ->call('postMessage')
        ->assertRedirect(route('admin.review'));

    expect(session('status'))->toContain('1 sur 3');
});

it('announces the publication when the accord completes the quorum', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    $review = app(ReviewService::class);

    foreach (User::factory()->count(2)->moderator()->create() as $moderator) {
        $review->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ReviewShow::class, ['article' => $article->refresh()])
        ->set('messageBody', 'troisieme accord')
        ->set('decision', 'accepted')
        ->call('postMessage')
        ->assertRedirect(route('admin.review'));

    expect($article->refresh()->status)->toBe(ArticleStatus::PUBLISHED)
        ->and(session('status'))->toContain('publié');
});

it('keeps the moderator in the thread for a message without decision', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ReviewShow::class, ['article' => $article])
        ->set('messageBody', 'une question sur le troisieme paragraphe')
        ->call('postMessage')
        ->assertNoRedirect()
        ->assertSee('Message posté dans le fil de revue.');
});

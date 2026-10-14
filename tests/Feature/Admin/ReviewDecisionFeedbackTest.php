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
        ->call('decide', 'accepted')
        ->assertRedirect(route('admin.review.index'));

    expect(session('success'))->toContain('1 sur 3');
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
        ->call('decide', 'accepted')
        ->assertRedirect(route('admin.review.index'));

    expect($article->refresh()->status)->toBe(ArticleStatus::PUBLISHED)
        ->and(session('success'))->toContain('publié');
});

it('keeps the moderator in the thread for a comment', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ReviewShow::class, ['article' => $article])
        ->set('messageBody', 'une question sur le troisieme paragraphe')
        ->call('comment')
        ->assertNoRedirect()
        ->assertSee('Commentaire posté dans le fil de revue.');

    $message = $article->reviewMessages()->sole();

    expect($message->decision)->toBeNull()
        ->and($article->refresh()->status)->toBe(ArticleStatus::PENDING);
});

it('shows a comment button and the decisions as buttons, never "Publier dans le fil"', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ReviewShow::class, ['article' => $article])
        ->assertSee('Commenter')
        ->assertSee('Refuser : déjà proposée')
        ->assertSeeHtml('wire:click="decide(\'rejected\')"')
        ->assertDontSee('Publier dans le fil');
});

it('refuses with the rule and the motive', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ReviewShow::class, ['article' => $article])
        ->set('messageBody', 'lien trompeur vers un autre site')
        ->call('decide', 'rejected')
        ->assertHasErrors('ruleRef')
        ->set('ruleRef', 'R4')
        ->call('decide', 'rejected')
        ->assertRedirect(route('admin.review.index'));

    $message = $article->reviewMessages()->sole();

    expect($article->refresh()->status)->toBe(ArticleStatus::REJECTED)
        ->and($message->decision)->toBe(ReviewDecision::REJECTED)
        ->and($message->rule_ref)->toBe('R4')
        ->and(session('success'))->toContain('refusé');
});

it('refuses a duplicate in one click under rule R7', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ReviewShow::class, ['article' => $article])
        ->call('rejectAsDuplicate')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.review.index'));

    $message = $article->reviewMessages()->sole();

    expect($article->refresh()->status)->toBe(ArticleStatus::REJECTED)
        ->and($message->decision)->toBe(ReviewDecision::REJECTED)
        ->and($message->rule_ref)->toBe(ReviewService::DUPLICATE_RULE)
        ->and($message->body)->toBe('Cette annonce a déjà été proposée.');
});

it('lets the moderator word the duplicate refusal', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ReviewShow::class, ['article' => $article])
        ->set('messageBody', 'Déjà proposée sous le numéro 42.')
        ->call('rejectAsDuplicate');

    expect($article->reviewMessages()->sole()->body)->toBe('Déjà proposée sous le numéro 42.');
});

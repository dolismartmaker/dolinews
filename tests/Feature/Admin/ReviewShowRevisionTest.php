<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\RevisionService;
use App\Livewire\Admin\ReviewShow;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * A revision act lands on the article the screen shows, never on
 * another one (revue F4).
 */
it('applies a revision that belongs to the article on screen', function (): void {
    $author = User::factory()->create();
    $article = Factory::publishedArticle($author);

    $revision = app(RevisionService::class)->propose(
        $article,
        $author,
        ['summary' => 'Resume corrige.'],
        'coquille',
    );

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ReviewShow::class, ['article' => $article])
        ->call('applyRevision', $revision->getKey());

    expect($revision->fresh()?->status)->toBe('applied');
});

it('refuses to apply the revision of another article', function (): void {
    $author = User::factory()->create();
    $shown = Factory::publishedArticle($author);
    $other = Factory::publishedArticle(User::factory()->create());

    $strayAuthor = $other->author_user_id;

    $stray = app(RevisionService::class)->propose(
        $other,
        User::query()->findOrFail($strayAuthor),
        ['summary' => 'Resume dune autre annonce.'],
        'coquille',
    );

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ReviewShow::class, ['article' => $shown])
        ->call('applyRevision', $stray->getKey());
})->throws(ModelNotFoundException::class);

it('refuses to reject the revision of another article', function (): void {
    $author = User::factory()->create();
    $shown = Factory::publishedArticle($author);
    $other = Factory::publishedArticle(User::factory()->create());

    $stray = app(RevisionService::class)->propose(
        $other,
        User::query()->findOrFail($other->author_user_id),
        ['summary' => 'Resume dune autre annonce.'],
        'coquille',
    );

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ReviewShow::class, ['article' => $shown])
        ->call('rejectRevision', $stray->getKey());
})->throws(ModelNotFoundException::class);

<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Livewire\Admin\ReviewQueue;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * The queue's own language filter (SPEC 5.1).
 *
 * It narrows what a moderator LOOKS at, never what they may do: the
 * queue stays open to the whole team, and unchecking shows it entirely.
 */
it('narrows the queue to the languages the moderator declared', function (): void {
    $author = User::factory()->create();

    $french = Factory::article($author, ['locale' => 'fr_FR', 'title' => 'Version francaise']);
    app(ArticleService::class)->submit($french, $author);

    $greek = Factory::article($author, ['locale' => 'el_GR', 'title' => 'Version grecque']);
    app(ArticleService::class)->submit($greek, $author);

    $moderator = User::factory()->moderator()->create([
        'email_verified_at' => now(),
        'review_locales' => ['fr_FR'],
    ]);

    Livewire::actingAs($moderator)
        ->test(ReviewQueue::class)
        ->assertSee('Version francaise')
        ->assertDontSee('Version grecque')
        // Unchecking is enough to see the rest: nothing is hidden by right.
        ->set('onlyMyLanguages', false)
        ->assertSee('Version grecque');
});

it('tells an empty queue apart from a filtered one', function (): void {
    $author = User::factory()->create();

    $greek = Factory::article($author, ['locale' => 'el_GR']);
    app(ArticleService::class)->submit($greek, $author);

    $moderator = User::factory()->moderator()->create([
        'email_verified_at' => now(),
        'review_locales' => ['fr_FR'],
    ]);

    Livewire::actingAs($moderator)
        ->test(ReviewQueue::class)
        ->assertSee('Décochez le filtre')
        ->assertDontSee('File vide');
});

it('shows the whole queue to a moderator who declared nothing', function (): void {
    $author = User::factory()->create();

    $greek = Factory::article($author, ['locale' => 'el_GR', 'title' => 'Version grecque']);
    app(ArticleService::class)->submit($greek, $author);

    $moderator = User::factory()->moderator()->create(['email_verified_at' => now()]);

    Livewire::actingAs($moderator)
        ->test(ReviewQueue::class)
        ->assertSee('Version grecque')
        // No declaration, no filter to offer.
        ->assertDontSee('que je relis');
});

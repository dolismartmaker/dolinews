<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Models\Media;
use App\Livewire\Admin\MediaList;
use App\Livewire\Admin\ReviewShow;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * Moderating what an image shows (rules R3/R6).
 *
 * A path and a MIME type say nothing about the picture: both screens have to
 * put the file itself under the moderator's eyes.
 */
function mediaFor(?int $articleId = null, array $overrides = []): Media
{
    return Media::query()->create(array_merge([
        'article_id' => $articleId,
        'path' => 'captures/ecran-de-configuration.webp',
        'mime' => 'image/webp',
        'width' => 1200,
        'height' => 800,
        'bytes' => 82_400,
        'hash' => hash('sha256', uniqid('', true)),
        'alt' => 'Écran de configuration',
    ], $overrides));
}

it('shows the image itself on the media list', function (): void {
    $media = mediaFor();

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(MediaList::class)
        // The file, not just its row: the thumbnail and the hover preview
        // both point at the stored image.
        ->assertSee($media->url())
        ->assertSee('Écran de configuration');
});

it('flags an upload no article claims', function (): void {
    // An orphan is what the nightly purge collects; a moderator reading the
    // list has to tell it apart from a bound file (SPEC 5.2).
    mediaFor();

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(MediaList::class)
        ->assertSee('orphelin');
});

it('links a bound media to the review of its article', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    mediaFor($article->getKey());

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(MediaList::class)
        ->assertSee(route('admin.review.show', $article->getKey()))
        ->assertDontSee('orphelin');
});

it('shows the album of a submission under review', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    $first = mediaFor($article->getKey(), ['path' => 'captures/une.webp', 'alt' => 'Première capture']);
    $second = mediaFor($article->getKey(), ['path' => 'captures/deux.webp', 'alt' => null]);

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ReviewShow::class, ['article' => $article])
        ->assertSee('Images jointes')
        ->assertSee($first->url())
        ->assertSee($second->url())
        ->assertSee('Première capture')
        // An image with no alternative text is a review remark of its own.
        ->assertSee('sans texte de remplacement');
});

it('says when a submission carries no image at all', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ReviewShow::class, ['article' => $article])
        ->assertSee('Aucune image jointe');
});

it('never shows another submission images', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    $other = mediaFor(Factory::article(User::factory()->create())->getKey(), [
        'path' => 'captures/autre-article.webp',
    ]);

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ReviewShow::class, ['article' => $article])
        ->assertDontSee($other->url());
});

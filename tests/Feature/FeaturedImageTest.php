<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleException;
use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Articles\RevisionService;
use App\Domain\Dolinews\Articles\TranslationService;
use App\Domain\Dolinews\Enums\ReviewDecision;
use App\Domain\Dolinews\Media\MediaService;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Media;
use App\Domain\Dolinews\Review\ReviewService;
use App\Livewire\Admin\ReviewShow;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * The image an announcement puts forward (SPEC 4.3): on the feed card, in
 * the sharing card, in the API and in the feeds.
 *
 * Designated by the author and reviewed with the article; inherited by a
 * translation from its source; failing both, the first image the body
 * cites - never a media merely bound to the article, which may have come
 * with a revision still under review.
 */
beforeEach(function (): void {
    Storage::fake(Media::DISK, ['url' => 'https://dolinews.test/storage']);
});

/**
 * A distinct image of the editor: identical bytes would be stored once.
 */
function featuredMedia(Editor $editor, int $width, ?string $alt = null): Media
{
    return app(MediaService::class)->store(UploadedFile::fake()->image('capture.png', $width, 90), $editor, $alt);
}

/**
 * The body Markdown citing a media as an image.
 */
function citing(Media $media): string
{
    return '![Capture]('.$media->url().')';
}

it('shows the designated image on the card, in the API and in the sharing card', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $image = featuredMedia($editor, 160, 'Écran des relances');

    $created = $this->withToken(Factory::apiToken($author))->postJson('/api/v1/articles', [
        'editor_id' => $editor->getKey(),
        'type' => 'announcement',
        'title' => 'Annonce illustrée',
        'summary' => 'Une annonce qui met une image en avant.',
        'body' => 'Texte sans image.',
        'locale' => 'fr_FR',
        'featured_media_id' => $image->getKey(),
        'submit' => true,
    ]);

    $created->assertStatus(201)
        ->assertJsonPath('data.featured_image.url', $image->absoluteUrl())
        ->assertJsonPath('data.featured_image.width', 160)
        ->assertJsonPath('data.featured_image.alt', 'Écran des relances');

    /** @var Article $article */
    $article = Article::query()->findOrFail($created->json('data.id'));

    // Designated, it is held by the article and survives the purge.
    expect($image->refresh()->article_id)->toBe($article->getKey());

    publishByQuorum($article);

    $this->get('/fr')
        ->assertOk()
        ->assertSee('src="'.$image->url().'"', false)
        ->assertSee('alt="Écran des relances"', false);

    $this->get('/fr/articles/'.$article->getKey())
        ->assertOk()
        ->assertSee('<meta property="og:image" content="'.$image->absoluteUrl().'">', false);
});

it('refuses an image of another editor and leaves no draft behind', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    [, $otherEditor] = Factory::contributorWithEditor();
    $foreign = featuredMedia($otherEditor, 170);

    $this->withToken(Factory::apiToken($author))->postJson('/api/v1/articles', [
        'editor_id' => $editor->getKey(),
        'type' => 'announcement',
        'title' => 'Image empruntée',
        'summary' => 'Une image qui ne lui appartient pas.',
        'body' => 'Texte.',
        'locale' => 'fr_FR',
        'featured_media_id' => $foreign->getKey(),
        'submit' => true,
    ])->assertStatus(409)->assertJsonPath('error', 'CONFLICT');

    // One transaction: a client that retries does not pile up drafts.
    expect(Article::query()->where('title', 'Image empruntée')->exists())->toBeFalse();
});

it('falls back on the first image the body cites, not on the first one bound', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $boundOnly = featuredMedia($editor, 180);
    $cited = featuredMedia($editor, 190);

    $article = Factory::publishedArticle($author, [
        'body' => "Intro.\n\n`![exemple](".$boundOnly->url().")`\n\n".citing($cited),
    ]);
    app(MediaService::class)->bindToArticle([$boundOnly->getKey(), $cited->getKey()], $article);

    // The first bound media is cited only inside a code span: an example,
    // not an illustration.
    expect($article->refresh()->featuredImage()?->getKey())->toBe($cited->getKey());
});

it('shows no image for a media bound to the article but cited nowhere', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $article = Factory::publishedArticle($author, ['title' => 'Sans illustration', 'body' => 'Texte.']);

    app(MediaService::class)->bindToArticle([featuredMedia($editor, 200)->getKey()], $article);

    expect($article->refresh()->featuredImage())->toBeNull();

    $this->get('/fr')->assertOk()->assertSee('Sans illustration')->assertDontSee('feed-card-image', false);
});

it('shows the image of its source on a translation, which designates none', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $image = featuredMedia($editor, 210);

    $source = Factory::article($author);
    app(ArticleService::class)->edit($source, $author, ['featured_media_id' => $image->getKey()]);
    $source = publishByQuorum($source);

    $translation = Factory::publishedTranslation($author, $source, 'en_US');

    expect($translation->featuredImage()?->getKey())->toBe($image->getKey());

    $draftTranslation = app(TranslationService::class)
        ->submitTranslation(Factory::article($author, ['title' => 'Source seule']), $author, 'de_DE', [
            'title' => 'Quelle', 'summary' => 'Kurz.', 'body' => 'Text.',
        ]);

    expect(fn () => app(ArticleService::class)->edit($draftTranslation, $author, ['featured_media_id' => $image->getKey()]))
        ->toThrow(ArticleException::class);
});

it('changes the image through a revision, and only once the review applies it', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $old = featuredMedia($editor, 220);

    $article = Factory::article($author);
    app(ArticleService::class)->edit($article, $author, ['featured_media_id' => $old->getKey()]);
    $article = publishByQuorum($article);

    $new = featuredMedia($editor, 230);

    $this->withToken(Factory::apiToken($author))->postJson('/api/v1/articles/'.$article->getKey().'/revisions', [
        'featured_media_id' => $new->getKey(),
        'media_ids' => [$new->getKey()],
        'motive' => 'Capture de la nouvelle interface',
    ])->assertStatus(201)->assertJsonPath('data.changed_fields', ['featured_media_id']);

    // Bound at proposal time, so the purge spares it, but shown nowhere yet.
    expect($new->refresh()->article_id)->toBe($article->getKey())
        ->and($article->refresh()->featuredImage()?->getKey())->toBe($old->getKey());

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ReviewShow::class, ['article' => $article])
        ->assertSee($old->url())
        ->assertSee($new->url());

    app(RevisionService::class)->apply(app(RevisionService::class)->pendingRevision($article));

    expect($article->refresh()->featuredImage()?->getKey())->toBe($new->getKey());
});

it('keeps a designated image through the orphan purge', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $image = featuredMedia($editor, 240);

    // Designated by the service alone, never bound: the reuse case, where
    // the bytes come back attached to nothing here.
    $article = Factory::article($author);
    app(ArticleService::class)->edit($article, $author, ['featured_media_id' => $image->getKey()]);

    expect($image->refresh()->article_id)->toBeNull();

    $this->travelTo(now()->addDays(2));

    app(MediaService::class)->purgeOrphans();

    expect(Media::query()->find($image->getKey()))->not->toBeNull();
});

it('carries the image and the logo of the source in the feeds', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $logo = featuredMedia($editor, 64);
    $editor->logo_media_id = $logo->getKey();
    $editor->save();

    $image = featuredMedia($editor, 250);
    $article = Factory::article($author, ['title' => 'Annonce des flux']);
    app(ArticleService::class)->edit($article, $author, ['featured_media_id' => $image->getKey()]);
    publishByQuorum($article);

    $item = collect($this->getJson('/feeds.json?locale=fr_FR')->assertOk()->json('items'))
        ->firstWhere('title', 'Annonce des flux');

    expect($item['image'])->toBe($image->absoluteUrl())
        ->and($item['authors'][0]['avatar'])->toBe($logo->absoluteUrl());

    $this->get('/feeds.xml?locale=fr_FR')
        ->assertOk()
        ->assertSee('<enclosure url="'.$image->absoluteUrl().'" length="'.$image->bytes.'" type="'.$image->mime.'"/>', false);
});

it('falls back on the logo of the source for the sharing card', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();
    $logo = featuredMedia($editor, 70);
    $editor->logo_media_id = $logo->getKey();
    $editor->save();

    $article = Factory::publishedArticle($author, ['body' => 'Texte.']);

    $this->get('/fr/articles/'.$article->getKey())
        ->assertOk()
        ->assertSee('<meta property="og:image" content="'.$logo->absoluteUrl().'">', false);
});

/**
 * Submit and gather the quorum of three on an existing draft.
 */
function publishByQuorum(Article $article): Article
{
    /** @var User $author */
    $author = $article->author;

    app(ArticleService::class)->submit($article, $author);

    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        app(ReviewService::class)->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    return $article->refresh();
}

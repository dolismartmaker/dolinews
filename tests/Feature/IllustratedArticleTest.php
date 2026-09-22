<?php

declare(strict_types=1);

use App\Domain\Dolinews\Enums\ReviewDecision;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Media;
use App\Domain\Dolinews\Review\ReviewService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Factory;

/**
 * End to end check of the illustrated publication (SPEC 5.2): a medium
 * deposited through the API, referenced by the body it illustrates, must
 * still be an <img> on the public page.
 *
 * The disk carries the absolute url production uses (APP_URL prefix),
 * not the relative one Storage::fake falls back to: the sanitizer
 * compares the image src against that prefix, so a test running on the
 * fallback would never exercise the real comparison.
 */
function depositMedium(User $user, int $editorId): array
{
    $binary = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        true,
    );

    $response = test()->withToken(Factory::apiToken($user))
        ->post('/api/v1/media', [
            'editor_id' => $editorId,
            'alt' => 'Capture de la matrice des droits',
            'file' => UploadedFile::fake()->createWithContent('capture.png', (string) $binary),
        ]);

    $response->assertStatus(201);

    return $response->json('data');
}

it('keeps the image on the public page of an illustrated article', function (): void {
    Storage::fake(Media::DISK, ['url' => 'https://dolinews.com/storage/media']);

    [$user, $editor] = Factory::contributorWithEditor();
    $medium = depositMedium($user, $editor->getKey());

    $created = test()->withToken(Factory::apiToken($user))
        ->postJson('/api/v1/articles', [
            'editor_id' => $editor->getKey(),
            'type' => 'announcement',
            'title' => 'Lancement du module',
            'summary' => 'Une annonce illustree par une capture deposee au prealable.',
            'body' => "Texte avant.\n\n![Capture](".$medium['url'].")\n\nTexte apres.",
            'locale' => 'fr_FR',
            'media_ids' => [$medium['id']],
            'submit' => true,
        ]);

    $created->assertStatus(201);

    /** @var Article $article */
    $article = Article::query()->findOrFail($created->json('data.id'));

    $review = app(ReviewService::class);

    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        $review->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    $article->refresh();

    expect($article->status->value)->toBe('published');

    $page = test()->get('/fr/articles/'.$article->getKey());

    $page->assertOk();
    $page->assertSee('<img', false);
    $page->assertSee($medium['url'], false);
});

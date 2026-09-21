<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleException;
use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Articles\RevisionService;
use App\Domain\Dolinews\Articles\TranslationService;
use App\Domain\Dolinews\Enums\ReviewDecision;
use App\Domain\Dolinews\Review\ReviewService;
use App\Models\User;
use Tests\Support\Factory;

/**
 * Translations and post-publication revisions (SPEC 5.4, D14).
 */
it('assigns the translation group id to every article, lone originals included', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);

    expect($article->translation_group_id)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($article->is_source)->toBeTrue();
});

it('binds translations to the group and forbids a locale twice', function (): void {
    $author = User::factory()->create();
    $source = Factory::publishedArticle($author);

    $translation = app(TranslationService::class)->submitTranslation($source, $author, 'en_US', [
        'title' => 'Module XY 2.1',
        'summary' => 'Security fix and v22 compat.',
        'body' => 'Details',
    ]);

    expect($translation->translation_group_id)->toBe($source->translation_group_id)
        ->and($translation->isTranslation())->toBeTrue()
        ->and($translation->source_revision_number)->toBe(0);

    app(TranslationService::class)->submitTranslation($source, $author, 'en_US', [
        'title' => 'Again',
        'summary' => 'Twice',
        'body' => 'Same locale',
    ]);
})->throws(ArticleException::class);

it('perimes translations when the source is revised', function (): void {
    $author = User::factory()->create();
    $source = Factory::publishedArticle($author);

    $translation = app(TranslationService::class)->submitTranslation($source, $author, 'en_US', [
        'title' => 'T',
        'summary' => 'S',
        'body' => 'B',
    ]);

    expect($translation->isStaleTranslation())->toBeFalse();

    // A correction of the source is proposed, then applied.
    $revision = app(RevisionService::class)->propose(
        $source,
        $author,
        ['summary' => 'Correctif de securite v22 (complet).'],
        'coquille dans le resume',
    );

    app(RevisionService::class)->apply($revision);

    $source = $source->refresh();
    $translation = $translation->refresh();

    expect($source->revision_number)->toBe(1)
        ->and($translation->isStaleTranslation())->toBeTrue();
});

it('keeps the original consultable through the snapshot', function (): void {
    $author = User::factory()->create();
    $source = Factory::publishedArticle($author);

    $revision = app(RevisionService::class)->propose(
        $source,
        $author,
        ['body' => 'Corps corrige.'],
        'erreur factuelle',
    );

    app(RevisionService::class)->apply($revision);

    // The snapshot holds the COMPLETE pre-application state: replaying
    // three diffs backwards fails at the first mistake (SPEC 5.4).
    expect($revision->refresh()->snapshot['body'])->toBe('## Details')
        ->and($revision->snapshot)->toHaveKeys(['title', 'summary', 'body', 'revision_number'])
        // The correction motive stays attached to the article.
        ->and($source->refresh()->lastAppliedRevision()?->motive)->toBe('erreur factuelle');
});

it('refuses a published article edit in place', function (): void {
    $author = User::factory()->create();
    $source = Factory::publishedArticle($author);

    app(ArticleService::class)->edit($source, $author, ['title' => 'Touched']);
})->throws(ArticleException::class);

it('allows only one pending revision per article', function (): void {
    $author = User::factory()->create();
    $source = Factory::publishedArticle($author);

    app(RevisionService::class)->propose($source, $author, ['title' => 'A'], 'un');
    app(RevisionService::class)->propose($source, $author, ['title' => 'B'], 'deux');
})->throws(ArticleException::class);

it('resyncs a translation revision with the source number', function (): void {
    $author = User::factory()->create();
    $source = Factory::publishedArticle($author);

    $translation = app(TranslationService::class)->submitTranslation($source, $author, 'en_US', [
        'title' => 'T',
        'summary' => 'S',
        'body' => 'B',
    ]);
    app(ArticleService::class)->submit($translation, $author);
    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        app(ReviewService::class)->postMessage($translation, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    // The source moves to revision 2.
    foreach ([1, 2] as $i) {
        $revision = app(RevisionService::class)->propose($source->refresh(), $author, ['summary' => 'v'.$i], 'maj '.$i);
        app(RevisionService::class)->apply($revision);
    }

    expect($translation->refresh()->isStaleTranslation())->toBeTrue();

    // The translation itself is revised: its numbers get back in phase.
    $tRevision = app(RevisionService::class)->propose(
        $translation->refresh(),
        $author,
        ['body' => 'Updated'],
        'mise a jour apres revision de la source',
    );

    app(RevisionService::class)->apply($tRevision);

    expect($translation->refresh()->isStaleTranslation())->toBeFalse();
});

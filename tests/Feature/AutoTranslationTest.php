<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\RevisionService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\PublicationMode;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Translation\AutoTranslationService;
use App\Domain\Dolinews\Translation\LibreTranslateEngine;
use App\Domain\Dolinews\Translation\TranslationEngine;
use App\Jobs\TranslateAnnouncement;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Factory;

/**
 * Machine translation of announcements (SPEC 5.7).
 *
 * The engine is faked here: what is tested is the service's own rules -
 * the editor's opt-in, the date inherited from the source, the human
 * translation nobody overwrites, and the refresh of a machine version
 * whose source was corrected.
 */

/**
 * An engine that prefixes every text with its target language, so a
 * translated field is recognisable, and that can be told to fail.
 */
function fakeEngine(bool $available = true, bool $fails = false): void
{
    app()->instance(TranslationEngine::class, new class($available, $fails) implements TranslationEngine
    {
        public function __construct(
            private readonly bool $available,
            private readonly bool $fails,
        ) {}

        public function isAvailable(): bool
        {
            return $this->available;
        }

        public function translate(string $text, string $sourceLocale, string $targetLocale): ?string
        {
            if ($this->fails) {
                return null;
            }

            return '['.substr($targetLocale, 0, 2).'] '.$text;
        }

        public function supportedLocales(): array
        {
            return [];
        }
    });
}

/**
 * A published announcement whose editor asked for machine translation.
 *
 * @return array{0: User, 1: Article}
 */
function autoTranslatedSource(bool $optIn = true): array
{
    [$author] = Factory::contributorWithEditor();

    $source = Factory::publishedArticle($author, [
        'title' => 'Module auto 1.0',
        'summary' => 'Correctif de securite.',
        'body' => '## Details',
        'locale' => 'fr_FR',
    ]);

    $editor = $source->editor;
    $editor->auto_translate = $optIn;
    $editor->save();

    return [$author, $source->refresh()];
}

it('translates a published announcement into the languages it lacks', function (): void {
    fakeEngine();
    [, $source] = autoTranslatedSource();

    $produced = app(AutoTranslationService::class)->sync($source);

    $versions = Article::query()
        ->where('translation_group_id', $source->translation_group_id)
        ->where('is_source', false)
        ->get();

    // Nine locales besides the source's, all published at once.
    expect($produced)->toBe(9)
        ->and($versions)->toHaveCount(9)
        ->and($versions->pluck('status')->unique()->all())->toBe([ArticleStatus::PUBLISHED])
        ->and($versions->pluck('auto_translated')->unique()->all())->toBe([true]);

    $spanish = $versions->firstWhere('locale', 'es_ES');

    expect($spanish?->title)->toBe('[es] Module auto 1.0')
        ->and($spanish?->publication_mode)->toBe(PublicationMode::TRANSLATION);
});

it('dates a machine version of its announcement', function (): void {
    fakeEngine();
    [, $source] = autoTranslatedSource();

    // A version dated of its own writing would pull the announcement
    // back to the top of the feed, one language at a time (SPEC 5.1).
    $this->travel(3)->days();

    app(AutoTranslationService::class)->sync($source);

    $spanish = Article::query()
        ->where('translation_group_id', $source->translation_group_id)
        ->where('locale', 'es_ES')
        ->firstOrFail();

    expect($spanish->published_at?->format('Y-m-d H:i:s'))
        ->toBe($source->refresh()->published_at?->format('Y-m-d H:i:s'));
});

it('translates nothing without the editor opt-in', function (): void {
    fakeEngine();
    [, $source] = autoTranslatedSource(optIn: false);

    expect(app(AutoTranslationService::class)->sync($source))->toBe(0);
});

it('translates nothing when no engine is configured', function (): void {
    fakeEngine(available: false);
    [, $source] = autoTranslatedSource();

    expect(app(AutoTranslationService::class)->sync($source))->toBe(0);
});

it('publishes no half-translated version', function (): void {
    fakeEngine(fails: true);
    [, $source] = autoTranslatedSource();

    // A translated title over a French body would be presented as the
    // Spanish reading of the announcement: all three fields or none.
    expect(app(AutoTranslationService::class)->sync($source))->toBe(0)
        ->and(Article::query()->where('is_source', false)->count())->toBe(0);
});

it('leaves a human translation untouched', function (): void {
    fakeEngine();
    [$author, $source] = autoTranslatedSource();

    Factory::publishedTranslation($author, $source, 'es_ES', [
        'title' => 'Version humaine',
        'summary' => 'Ecrite a la main.',
        'body' => '## A la main',
    ]);

    app(AutoTranslationService::class)->sync($source->refresh());

    $spanish = Article::query()
        ->where('translation_group_id', $source->translation_group_id)
        ->where('locale', 'es_ES')
        ->firstOrFail();

    expect($spanish->title)->toBe('Version humaine')
        ->and($spanish->auto_translated)->toBeFalse();
});

it('refreshes a machine version after its source was corrected', function (): void {
    fakeEngine();
    [$author, $source] = autoTranslatedSource();

    app(AutoTranslationService::class)->sync($source);

    $revision = app(RevisionService::class)->propose(
        $source,
        $author,
        ['title' => 'Module auto 1.0.1'],
        'Numero de version corrige.',
    );
    app(RevisionService::class)->apply($revision);

    app(AutoTranslationService::class)->sync($source->refresh());

    $spanish = Article::query()
        ->where('translation_group_id', $source->translation_group_id)
        ->where('locale', 'es_ES')
        ->firstOrFail();

    // Rewritten from the corrected text, through the revision circuit:
    // a published article never changes silently (SPEC 5.4).
    expect($spanish->title)->toBe('[es] Module auto 1.0.1')
        ->and($spanish->source_revision_number)->toBe($source->refresh()->revision_number)
        ->and($spanish->isStaleTranslation())->toBeFalse()
        ->and($spanish->lastAppliedRevision())->not->toBeNull();
});

it('queues the translation of a freshly published announcement', function (): void {
    Queue::fake();

    [$author] = Factory::contributorWithEditor();
    $editor = Factory::editorFor($author);
    $editor->auto_translate = true;
    $editor->save();

    Factory::publishedArticle($author, ['title' => 'Module file 1.0']);

    // Queued rather than inline: ten languages are ten calls to an
    // engine, which a moderator's acceptance has no reason to wait for.
    Queue::assertPushed(TranslateAnnouncement::class);
});

it('reads a translation from a LibreTranslate-compatible engine', function (): void {
    config()->set('dolinews.translation.endpoint', 'https://translate.test');

    Http::fake([
        'translate.test/translate' => Http::response(['translatedText' => 'Hola'], 200),
    ]);

    $engine = new LibreTranslateEngine;

    expect($engine->isAvailable())->toBeTrue()
        ->and($engine->translate('Bonjour', 'fr_FR', 'es_ES'))->toBe('Hola');
});

it('returns nothing when the engine refuses the request', function (): void {
    config()->set('dolinews.translation.endpoint', 'https://translate.test');

    Http::fake([
        'translate.test/translate' => Http::response('nope', 500),
    ]);

    expect((new LibreTranslateEngine)->translate('Bonjour', 'fr_FR', 'es_ES'))->toBeNull();
});

it('reports itself unavailable without an endpoint', function (): void {
    config()->set('dolinews.translation.endpoint', '');

    expect((new LibreTranslateEngine)->isAvailable())->toBeFalse();
});

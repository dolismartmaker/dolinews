<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\RevisionService;
use App\Domain\Dolinews\Articles\TranslationMandateService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\PublicationMode;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Translation\AutoTranslationException;
use App\Domain\Dolinews\Translation\AutoTranslationService;
use App\Domain\Dolinews\Translation\DeepLEngine;
use App\Domain\Dolinews\Translation\ProxyTranslationEngine;
use App\Domain\Dolinews\Translation\TranslationEngine;
use App\Domain\Dolinews\Translation\TranslationRouter;
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
 * A shared engine that prefixes every text with its target language, so
 * a translated field is recognisable, and that can be told to fail.
 *
 * Bound where the deployment binds the real one, so the routing under
 * test is the real routing.
 */
function fakeEngine(bool $available = true, bool $fails = false): object
{
    $engine = new class($available, $fails) implements TranslationEngine
    {
        /** @var array<int, array<int, string>> */
        public array $batches = [];

        public function __construct(
            private readonly bool $available,
            private readonly bool $fails,
        ) {}

        public function isAvailable(): bool
        {
            return $this->available;
        }

        public function translateBatch(array $texts, string $sourceLocale, string $targetLocale): ?array
        {
            if ($this->fails) {
                return null;
            }

            $this->batches[] = $texts;

            return array_map(
                static fn (string $text): string => '['.substr($targetLocale, 0, 2).'] '.$text,
                array_values($texts),
            );
        }

        public function supportedLocales(): array
        {
            return [];
        }
    };

    app()->instance(TranslationEngine::class, $engine);

    return $engine;
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

it('sends a batch to the shared endpoint and strips its html breaks', function (): void {
    config()->set('dolinews.translation.endpoint', 'https://translate.test/api/v1');
    config()->set('dolinews.translation.token', 'jeton');

    Http::fake([
        'translate.test/api/v1/translate' => Http::response([
            'success' => true,
            'translations' => ['Hola<br />', 'Mundo'],
            'errors' => null,
        ], 200),
    ]);

    $engine = new ProxyTranslationEngine('https://translate.test/api/v1', 'jeton');

    // One call for the whole batch, and the <br /> the endpoint inserts
    // removed: the body of an announcement is Markdown, never HTML (D5).
    expect($engine->translateBatch(['Bonjour', 'Monde'], 'fr_FR', 'es_ES'))
        ->toBe(['Hola', 'Mundo']);

    Http::assertSentCount(1);

    Http::assertSent(function ($request): bool {
        return $request['source_lang'] === 'FR'
            && $request['target_lang'] === 'ES'
            && $request['text'] === ['Bonjour', 'Monde']
            && $request->hasHeader('Authorization', 'Bearer jeton');
    });
});

it('treats a spent allowance as a state, not a failure', function (): void {
    Http::fake([
        'translate.test/*' => Http::response([
            'success' => false,
            'error' => 'allowance_exhausted',
            'message' => 'Le volume inclus dans votre abonnement est épuisé pour la période en cours.',
        ], 402),
    ]);

    // Nothing comes back, and nothing is published: the editor's screen
    // says why, the feed stays as it was.
    expect((new ProxyTranslationEngine('https://translate.test/api/v1', 'jeton'))
        ->translateBatch(['Bonjour'], 'fr_FR', 'es_ES'))->toBeNull();
});

it('refuses an answer that does not match the batch', function (): void {
    Http::fake([
        'translate.test/*' => Http::response([
            'success' => true,
            'translations' => ['Hola'],
            'errors' => null,
        ], 200),
    ]);

    // Two sent, one returned: pairing them would put the Spanish title
    // on the summary.
    expect((new ProxyTranslationEngine('https://translate.test/api/v1', 'jeton'))
        ->translateBatch(['Bonjour', 'Monde'], 'fr_FR', 'es_ES'))->toBeNull();
});

it('reports the shared engine unavailable without an endpoint or a token', function (): void {
    expect((new ProxyTranslationEngine('', 'jeton'))->isAvailable())->toBeFalse()
        ->and((new ProxyTranslationEngine('https://translate.test/api/v1', ''))->isAvailable())->toBeFalse();
});

it('reads a batch from deepl with an editor key', function (): void {
    Http::fake([
        'api-free.deepl.com/v2/translate' => Http::response([
            'translations' => [
                ['detected_source_language' => 'FR', 'text' => 'Hola'],
                ['detected_source_language' => 'FR', 'text' => 'Mundo'],
            ],
        ], 200),
    ]);

    $engine = new DeepLEngine('cle-editeur:fx');

    expect($engine->translateBatch(['Bonjour', 'Monde'], 'fr_FR', 'es_ES'))->toBe(['Hola', 'Mundo']);

    // A free key answers on another host; the paid one would 403 it and
    // that would read as a wrong key.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api-free.deepl.com')
        && $request->hasHeader('Authorization', 'DeepL-Auth-Key cle-editeur:fx'));
});

it('sends a paid deepl key to the paid host', function (): void {
    Http::fake([
        'api.deepl.com/v2/translate' => Http::response(['translations' => [['text' => 'Hola']]], 200),
    ]);

    expect((new DeepLEngine('cle-payante'))->translateBatch(['Bonjour'], 'fr_FR', 'es_ES'))->toBe(['Hola']);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '//api.deepl.com'));
});

it('treats a spent deepl key as a state, not a failure', function (): void {
    Http::fake(['api-free.deepl.com/*' => Http::response('quota', 456)]);

    expect((new DeepLEngine('cle:fx'))->translateBatch(['Bonjour'], 'fr_FR', 'es_ES'))->toBeNull();
});

it('reports a deepl engine unavailable without a key', function (): void {
    expect((new DeepLEngine(''))->isAvailable())->toBeFalse();
});

it('never hands a fenced code block to the engine', function (): void {
    $engine = fakeEngine();
    [$author] = Factory::contributorWithEditor();

    $body = "Voici la configuration :\n\n```\nDOLINEWS_QUEUE_CEILING=5\n```\n\nEt la suite.";

    $source = Factory::publishedArticle($author, [
        'title' => 'Module code 1.0',
        'summary' => 'Resume.',
        'body' => $body,
        'locale' => 'fr_FR',
    ]);

    $editor = $source->editor;
    $editor->auto_translate = true;
    $editor->save();

    app(AutoTranslationService::class)->sync($source->refresh());

    $spanish = Article::query()
        ->where('translation_group_id', $source->translation_group_id)
        ->where('locale', 'es_ES')
        ->firstOrFail();

    // The configuration line comes back untouched: an engine would have
    // turned it into a sentence.
    expect($spanish->body)->toContain('DOLINEWS_QUEUE_CEILING=5')
        ->and($spanish->body)->not->toContain('[es] DOLINEWS_QUEUE_CEILING=5')
        ->and($spanish->body)->toContain('[es] Voici la configuration :')
        ->and($spanish->body)->toContain('[es] Et la suite.');

    // Title, summary and the two prose blocks in a single call.
    expect($engine->batches[0])->toHaveCount(4);
});

it('counts the characters of the shared route against the editor', function (): void {
    fakeEngine();
    [, $source] = autoTranslatedSource();

    app(AutoTranslationService::class)->sync($source);

    $spent = app(TranslationRouter::class)->spent($source->editor);

    // Title, summary and body, nine times over.
    $perLanguage = mb_strlen($source->title) + mb_strlen($source->summary) + mb_strlen($source->body);

    expect($spent)->toBe($perLanguage * 9);
});

it('stops translating when the editor spent its monthly allowance', function (): void {
    config()->set('dolinews.translation.monthly_characters', 10);

    fakeEngine();
    [, $source] = autoTranslatedSource();

    app(TranslationRouter::class)->record($source->editor, 10);

    // Stated, not broken: the screen says the date it comes back, and
    // the announcement stays published as it is.
    expect(app(AutoTranslationService::class)->sync($source))->toBe(0);
});

it('leaves an editor on its own key out of the shared allowance', function (): void {
    config()->set('dolinews.translation.monthly_characters', 10);

    Http::fake([
        'api-free.deepl.com/*' => Http::response([
            'translations' => array_fill(0, 3, ['text' => 'texto']),
        ], 200),
    ]);

    fakeEngine();
    [, $source] = autoTranslatedSource();

    $editor = $source->editor;
    $editor->translation_api_key = 'cle-editeur:fx';
    $editor->translation_key_set_at = now();
    $editor->save();

    app(TranslationRouter::class)->record($source->editor, 10);

    // The allowance is spent and it changes nothing: the editor draws on
    // its own supplier (SPEC 5.7).
    expect(app(AutoTranslationService::class)->sync($source->refresh()))->toBe(9)
        ->and(app(TranslationRouter::class)->spent($source->editor))->toBe(10);
});

it('produces only the languages the editor asked for', function (): void {
    fakeEngine();
    [, $source] = autoTranslatedSource();

    $editor = $source->editor;
    $editor->translation_locales = ['es_ES', 'en_US'];
    $editor->save();

    $produced = app(AutoTranslationService::class)->sync($source->refresh());

    $locales = Article::query()
        ->where('translation_group_id', $source->translation_group_id)
        ->where('is_source', false)
        ->pluck('locale')
        ->sort()
        ->values()
        ->all();

    // Two chosen languages, two versions, and two ninths of the
    // allowance spent rather than all of it.
    expect($produced)->toBe(2)
        ->and($locales)->toBe(['en_US', 'es_ES']);
});

it('reads an empty selection as every language', function (): void {
    fakeEngine();
    [, $source] = autoTranslatedSource();

    $editor = $source->editor;
    $editor->translation_locales = [];
    $editor->save();

    // An editor that unticks everything asks for the default, never for
    // "translate into nothing".
    expect(app(AutoTranslationService::class)->sync($source->refresh()))->toBe(9);
});

it('keeps correcting a version whose language was dropped', function (): void {
    fakeEngine();
    [$author, $source] = autoTranslatedSource();

    $editor = $source->editor;
    $editor->translation_locales = ['es_ES'];
    $editor->save();

    app(AutoTranslationService::class)->sync($source->refresh());

    // Spanish leaves the list after the version went out.
    $editor->translation_locales = ['en_US'];
    $editor->save();

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

    // Still online, and corrected: leaving it describing a text that
    // changed would be publishing something false on purpose (SPEC 5.4).
    expect($spanish->title)->toBe('[es] Module auto 1.0.1');
});

it('translates one announcement into one language on demand', function (): void {
    fakeEngine();
    [$author, $source] = autoTranslatedSource(optIn: false);

    // The click is the consent: the global switch is off and the
    // explicit demand stands on its own (SPEC 5.7).
    $this->actingAs($author->refresh())
        ->post('/account/articles/'.$source->getKey().'/translations/auto', ['locale' => 'es_ES'])
        ->assertRedirect();

    $spanish = Article::query()
        ->where('translation_group_id', $source->translation_group_id)
        ->where('locale', 'es_ES')
        ->firstOrFail();

    expect($spanish->title)->toBe('[es] Module auto 1.0')
        ->and($spanish->status)->toBe(ArticleStatus::PUBLISHED)
        ->and($spanish->auto_translated)->toBeTrue()
        ->and($spanish->published_at?->format('Y-m-d H:i:s'))
        ->toBe($source->refresh()->published_at?->format('Y-m-d H:i:s'));

    // One language asked for, one produced.
    expect(Article::query()->where('translation_group_id', $source->translation_group_id)
        ->where('is_source', false)->count())->toBe(1);
});

it('ignores the editor language selection on an explicit demand', function (): void {
    fakeEngine();
    [$author, $source] = autoTranslatedSource();

    $editor = $source->editor;
    $editor->translation_locales = ['en_US'];
    $editor->save();

    // The selection says what happens by itself, not what may be asked.
    $this->actingAs($author->refresh())
        ->post('/account/articles/'.$source->getKey().'/translations/auto', ['locale' => 'el_GR'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Article::query()->where('translation_group_id', $source->translation_group_id)
        ->where('locale', 'el_GR')->exists())->toBeTrue();
});

it('says why an on-demand translation was refused', function (): void {
    config()->set('dolinews.translation.monthly_characters', 10);

    fakeEngine();
    [$author, $source] = autoTranslatedSource();

    app(TranslationRouter::class)->record($source->editor, 10);

    // A spent allowance is a state with a date, not a breakage.
    $this->actingAs($author->refresh())
        ->post('/account/articles/'.$source->getKey().'/translations/auto', ['locale' => 'es_ES'])
        ->assertRedirect()
        ->assertSessionHasErrors('locale');

    expect(Article::query()->where('is_source', false)->count())->toBe(0);
});

it('refuses a language the announcement already has', function (): void {
    fakeEngine();
    [$author, $source] = autoTranslatedSource();

    Factory::publishedTranslation($author, $source, 'es_ES', ['title' => 'Version humaine']);

    $this->actingAs($author->refresh())
        ->post('/account/articles/'.$source->getKey().'/translations/auto', ['locale' => 'es_ES'])
        ->assertRedirect()
        ->assertSessionHasErrors('locale');

    // The human version is untouched, which is the whole point.
    expect(Article::query()->where('translation_group_id', $source->translation_group_id)
        ->where('locale', 'es_ES')->value('title'))->toBe('Version humaine');
});

it('keeps the machine button away from a mandated translator', function (): void {
    fakeEngine();
    [$author, $source] = autoTranslatedSource();

    $translator = Factory::contributorWithoutEditor();
    app(TranslationMandateService::class)
        ->grant($source->editor, $author, $translator);

    // A machine version publishes without review: a mandated translator
    // may write one by hand, never trigger one (SPEC 5.1/5.7).
    $this->actingAs($translator->refresh())
        ->post('/account/articles/'.$source->getKey().'/translations/auto', ['locale' => 'es_ES'])
        ->assertForbidden();

    $this->actingAs($translator->refresh())
        ->get('/account/articles/'.$source->getKey().'/translations/new')
        ->assertOk()
        ->assertDontSee(route('account.articles.translations.auto', $source), escape: false);
});

it('offers the machine button to the editor on its own announcement', function (): void {
    fakeEngine();
    [$author, $source] = autoTranslatedSource();

    $this->actingAs($author->refresh())
        ->get('/account/articles/'.$source->getKey().'/translations/new')
        ->assertOk()
        ->assertSee(route('account.articles.translations.auto', $source), escape: false)
        ->assertSee($source->title);
});

/**
 * An engine that answers with texts of a chosen length, the way German
 * and Polish come back longer than the French they translate.
 */
function inflatingEngine(string $title, string $summary): void
{
    app()->instance(TranslationEngine::class, new class($title, $summary) implements TranslationEngine
    {
        public function __construct(
            private readonly string $title,
            private readonly string $summary,
        ) {}

        public function isAvailable(): bool
        {
            return true;
        }

        public function translateBatch(array $texts, string $sourceLocale, string $targetLocale): ?array
        {
            $answer = array_values($texts);
            $answer[0] = $this->title;
            $answer[1] = $this->summary;

            return $answer;
        }

        public function supportedLocales(): array
        {
            return [];
        }
    });
}

it('cuts an overlong summary at its last whole sentence', function (): void {
    $first = rtrim(str_repeat('alpha ', 58)).'.';
    $second = rtrim(str_repeat('beta ', 40)).'.';

    inflatingEngine('Titre traduit', $first.' '.$second);
    [, $source] = autoTranslatedSource();

    app(AutoTranslationService::class)->translateInto($source, 'de_DE');

    $german = Article::query()
        ->where('translation_group_id', $source->translation_group_id)
        ->where('locale', 'de_DE')
        ->firstOrFail();

    // What is left reads as it was written, no ellipsis needed.
    expect($german->summary)->toBe($first)
        ->and(mb_strlen($german->summary))->toBeLessThanOrEqual(500);
});

it('cuts an overlong summary at a word when no sentence boundary fits', function (): void {
    $single = rtrim(str_repeat('gamma ', 90)).'.';

    inflatingEngine('Titre traduit', $single);
    [, $source] = autoTranslatedSource();

    app(AutoTranslationService::class)->translateInto($source, 'de_DE');

    $german = Article::query()
        ->where('translation_group_id', $source->translation_group_id)
        ->where('locale', 'de_DE')
        ->firstOrFail();

    expect(mb_strlen($german->summary))->toBeLessThanOrEqual(500)
        ->and($german->summary)->toEndWith('...')
        // Cut on a word boundary, never in the middle of one.
        ->and($german->summary)->not->toContain('gam...');
});

it('skips a version whose translated title overruns the column', function (): void {
    inflatingEngine(rtrim(str_repeat('titre ', 50)), 'Resume court.');
    [, $source] = autoTranslatedSource();

    // A clipped title reads as a sentence broken off wherever it shows,
    // so the version is skipped rather than shortened.
    expect(fn () => app(AutoTranslationService::class)->translateInto($source, 'de_DE'))
        ->toThrow(AutoTranslationException::class);

    expect(Article::query()
        ->where('translation_group_id', $source->translation_group_id)
        ->where('locale', 'de_DE')
        ->exists())->toBeFalse();
});

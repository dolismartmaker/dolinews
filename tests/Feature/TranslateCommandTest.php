<?php

declare(strict_types=1);

use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\TranslationUsage;
use App\Domain\Dolinews\Projects\ProjectService;
use App\Domain\Dolinews\Translation\TranslationEngine;
use App\Domain\Dolinews\Translation\TranslationRouter;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Tests\Support\Factory;

/**
 * Batch machine translation from the command line (SPEC 5.7).
 *
 * The queued job only fires on a publication or a revision; this command
 * is the catch-up, and what is tested here is its own rules: the three
 * scopes, the languages asked for, the editor's opt-in it never lifts,
 * and a dry run that writes nothing.
 */

/**
 * The shared engine, faked: every text comes back prefixed with its
 * target language, so a translated field is recognisable. It keeps the
 * batches it was handed, which is what a replay is judged on - there
 * being nothing written to look at.
 */
function translateCommandEngine(): object
{
    $engine = new class implements TranslationEngine
    {
        /** @var array<int, array{target: string, texts: array<int, string>}> */
        public array $calls = [];

        public function isAvailable(): bool
        {
            return true;
        }

        public function translateBatch(array $texts, string $sourceLocale, string $targetLocale): ?array
        {
            $this->calls[] = ['target' => $targetLocale, 'texts' => array_values($texts)];

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
 * A contributor and its editor, machine translation still off.
 *
 * The opt-in is deliberately NOT set here: the queued job fires on
 * publication, so an editor that already asked for translation would
 * have its announcements translated by the factory rather than by the
 * command under test. Every case below publishes first, then enables.
 *
 * @return array{0: User, 1: Editor}
 */
function translatingEditor(): array
{
    return Factory::contributorWithEditor();
}

/**
 * Turn machine translation on, once the announcements are published.
 */
function enableTranslation(Editor $editor): void
{
    $editor->auto_translate = true;
    $editor->save();
}

/**
 * The published language versions of an announcement.
 *
 * @return Collection<int, Article>
 */
function versionsOf(Article $source): Collection
{
    return Article::query()
        ->where('translation_group_id', $source->translation_group_id)
        ->where('is_source', false)
        ->get();
}

it('translates one announcement into the languages asked for', function (): void {
    translateCommandEngine();
    [$author, $editor] = translatingEditor();
    $source = Factory::publishedArticle($author);
    enableTranslation($editor);

    $this->artisan('dolinews:translate', [
        '--article' => (string) $source->getKey(),
        '--locale' => ['es_ES', 'de_DE'],
    ])->assertSuccessful();

    $versions = versionsOf($source);

    expect($versions->pluck('locale')->sort()->values()->all())->toBe(['de_DE', 'es_ES'])
        ->and($versions->pluck('status')->unique()->all())->toBe([ArticleStatus::PUBLISHED])
        ->and($versions->pluck('auto_translated')->unique()->all())->toBe([true])
        ->and($versions->firstWhere('locale', 'es_ES')?->title)->toBe('[es] Module XY 2.1');
});

it('fills every language the editor asked for when none is named', function (): void {
    translateCommandEngine();
    [$author, $editor] = translatingEditor();
    $source = Factory::publishedArticle($author);
    enableTranslation($editor);

    $this->artisan('dolinews:translate', ['--article' => (string) $source->getKey()])
        ->assertSuccessful();

    // Nine content locales besides the source's, the state an editor
    // starts in being "every language the service offers".
    expect(versionsOf($source))->toHaveCount(9);
});

it('covers every announcement of a project sheet', function (): void {
    translateCommandEngine();
    [$author, $editor] = translatingEditor();

    $project = app(ProjectService::class)
        ->create($editor, ['name' => 'CapTodo', 'summary' => 'Un module de taches.']);

    $first = Factory::publishedArticle($author, [
        'project_id' => $project->getKey(),
        'title' => 'CapTodo 1.0',
        'version' => '1.0.0',
    ]);
    $second = Factory::publishedArticle($author, [
        'project_id' => $project->getKey(),
        'title' => 'CapTodo 1.1',
        'version' => '1.1.0',
    ]);
    enableTranslation($editor);

    $this->artisan('dolinews:translate', [
        '--project' => 'captodo',
        '--locale' => ['it_IT'],
    ])->assertSuccessful();

    expect(versionsOf($first)->pluck('locale')->all())->toBe(['it_IT'])
        ->and(versionsOf($second)->pluck('locale')->all())->toBe(['it_IT']);
});

it('covers the announcements of an editor, those without a sheet included', function (): void {
    translateCommandEngine();
    [$author, $editor] = translatingEditor();

    $project = app(ProjectService::class)
        ->create($editor, ['name' => 'CapGed', 'summary' => 'Un module de documents.']);

    $onSheet = Factory::publishedArticle($author, [
        'project_id' => $project->getKey(),
        'title' => 'CapGed 2.0',
        'version' => '2.0.0',
    ]);

    // An announcement with no project belongs to its editor all the same
    // (SPEC 5.3): scoping on the sheets would leave it out.
    $loose = Factory::publishedArticle($author, [
        'type' => 'announcement',
        'focus' => null,
        'title' => 'Ouverture de la boutique',
    ]);
    enableTranslation($editor);

    $this->artisan('dolinews:translate', [
        '--editor' => $editor->slug,
        '--locale' => ['pl_PL'],
    ])->assertSuccessful();

    expect(versionsOf($onSheet)->pluck('locale')->all())->toBe(['pl_PL'])
        ->and(versionsOf($loose)->pluck('locale')->all())->toBe(['pl_PL']);
});

it('writes nothing on a dry run', function (): void {
    translateCommandEngine();
    [$author, $editor] = translatingEditor();
    $source = Factory::publishedArticle($author);
    enableTranslation($editor);

    $this->artisan('dolinews:translate', [
        '--article' => (string) $source->getKey(),
        '--locale' => ['el_GR'],
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(versionsOf($source))->toHaveCount(0);
});

it('refuses an editor that did not enable machine translation', function (): void {
    translateCommandEngine();
    [$author] = translatingEditor();
    $source = Factory::publishedArticle($author);

    // SPEC 5.7 lets the editor's own click stand for consent; a command
    // run by the operator is not that click, and no option lifts it.
    $this->artisan('dolinews:translate', [
        '--article' => (string) $source->getKey(),
        '--locale' => ['es_ES'],
    ])->assertSuccessful();

    expect(versionsOf($source))->toHaveCount(0);
});

it('is not bounded by the language selection of the editor', function (): void {
    translateCommandEngine();
    [$author, $editor] = translatingEditor();

    $source = Factory::publishedArticle($author);

    $editor->translation_locales = ['en_US'];
    $editor->save();
    enableTranslation($editor);

    // The selection says what happens by itself, not what may be asked
    // for (SPEC 5.7, same reasoning as the single-announcement button).
    $this->artisan('dolinews:translate', [
        '--article' => (string) $source->getKey(),
        '--locale' => ['ro_RO'],
    ])->assertSuccessful();

    expect(versionsOf($source)->pluck('locale')->all())->toBe(['ro_RO']);

    // Without --locale, the selection applies again.
    $this->artisan('dolinews:translate', ['--article' => (string) $source->getKey()])
        ->assertSuccessful();

    expect(versionsOf($source)->pluck('locale')->sort()->values()->all())
        ->toBe(['en_US', 'ro_RO']);
});

it('translates the source of a translation it is pointed at', function (): void {
    translateCommandEngine();
    [$author, $editor] = translatingEditor();
    $source = Factory::publishedArticle($author);
    $english = Factory::publishedTranslation($author, $source, 'en_US');
    enableTranslation($editor);

    $this->artisan('dolinews:translate', [
        '--article' => (string) $english->getKey(),
        '--locale' => ['nl_NL'],
    ])->assertSuccessful();

    expect(versionsOf($source)->pluck('locale')->sort()->values()->all())
        ->toBe(['en_US', 'nl_NL']);
});

it('leaves a language already there alone', function (): void {
    translateCommandEngine();
    [$author, $editor] = translatingEditor();
    $source = Factory::publishedArticle($author);
    $human = Factory::publishedTranslation($author, $source, 'es_ES', [
        'title' => 'Traduction humaine',
    ]);
    enableTranslation($editor);

    $this->artisan('dolinews:translate', [
        '--article' => (string) $source->getKey(),
        '--locale' => ['es_ES'],
    ])->assertSuccessful();

    expect($human->refresh()->title)->toBe('Traduction humaine')
        ->and($human->auto_translated)->toBeFalse();
});

it('refuses a language the service does not publish in', function (): void {
    translateCommandEngine();
    [$author, $editor] = translatingEditor();
    $source = Factory::publishedArticle($author);
    enableTranslation($editor);

    $this->artisan('dolinews:translate', [
        '--article' => (string) $source->getKey(),
        '--locale' => ['ja_JP'],
    ])->assertFailed();

    expect(versionsOf($source))->toHaveCount(0);
});

it('refuses more than one target', function (): void {
    translateCommandEngine();
    [$author, $editor] = translatingEditor();
    $source = Factory::publishedArticle($author);
    enableTranslation($editor);

    $this->artisan('dolinews:translate', [
        '--article' => (string) $source->getKey(),
        '--editor' => $editor->slug,
    ])->assertFailed();

    expect(versionsOf($source))->toHaveCount(0);
});

it('stops at the limit it was given', function (): void {
    translateCommandEngine();
    [$author, $editor] = translatingEditor();

    Factory::publishedArticle($author, ['title' => 'Annonce ancienne']);
    $this->travel(2)->days();
    $recent = Factory::publishedArticle($author, ['title' => 'Annonce recente']);
    enableTranslation($editor);

    // Newest first, like every other listing of the feed.
    $this->artisan('dolinews:translate', [
        '--editor' => $editor->slug,
        '--locale' => ['pt_PT'],
        '--limit' => '1',
    ])->assertSuccessful();

    expect(versionsOf($recent)->pluck('locale')->all())->toBe(['pt_PT'])
        ->and(Article::query()->where('locale', 'pt_PT')->count())->toBe(1);
});

/**
 * A machine version already online, as the command produces it.
 */
function machineVersion(User $author, Article $source, string $locale): Article
{
    $translation = Factory::publishedTranslation($author, $source, $locale);
    $translation->auto_translated = true;
    $translation->save();

    return $translation->refresh();
}

it('sends the languages already produced again, and applies nothing', function (): void {
    $engine = translateCommandEngine();
    [$author, $editor] = translatingEditor();
    $source = Factory::publishedArticle($author);
    $spanish = machineVersion($author, $source, 'es_ES');
    enableTranslation($editor);

    $before = Article::query()->count();
    $revision = $spanish->revision_number;
    $engine->calls = [];

    $this->artisan('dolinews:translate', [
        '--editor' => $editor->slug,
        '--replay' => true,
    ])->assertSuccessful();

    // The batch left, with the source text of the announcement.
    expect($engine->calls)->toHaveCount(1)
        ->and($engine->calls[0]['target'])->toBe('es_ES')
        ->and($engine->calls[0]['texts'][0])->toBe($source->title);

    // And nothing came of it: no version added, none rewritten, no
    // revision proposed on the one already online.
    $spanish->refresh();

    expect(Article::query()->count())->toBe($before)
        ->and($spanish->title)->toBe('Module XY 2.1 (es_ES)')
        ->and($spanish->revision_number)->toBe($revision)
        ->and($spanish->revisions()->count())->toBe(0);
});

it('replays a spent monthly ceiling, and books nothing against it', function (): void {
    $engine = translateCommandEngine();
    [$author, $editor] = translatingEditor();
    $source = Factory::publishedArticle($author);
    machineVersion($author, $source, 'de_DE');
    enableTranslation($editor);

    // The ceiling measures what an editor spends to appear; a replay
    // publishes nothing and the editor did not ask for it.
    $usage = TranslationUsage::query()->create([
        'editor_id' => $editor->getKey(),
        'period' => app(TranslationRouter::class)->period(),
        'characters' => app(TranslationRouter::class)->ceiling(),
    ]);

    $engine->calls = [];

    $this->artisan('dolinews:translate', [
        '--editor' => $editor->slug,
        '--replay' => true,
    ])->assertSuccessful();

    expect($engine->calls)->toHaveCount(1)
        ->and($usage->refresh()->characters)->toBe(app(TranslationRouter::class)->ceiling())
        ->and(TranslationUsage::query()->count())->toBe(1);
});

it('replays on the shared engine, never on the editor own key', function (): void {
    $engine = translateCommandEngine();
    [$author, $editor] = translatingEditor();
    $source = Factory::publishedArticle($author);
    machineVersion($author, $source, 'it_IT');
    enableTranslation($editor);

    // Replaying on a third party's key would spend its money on our
    // endpoint's corpus.
    $editor->translation_api_key = 'cle-deepl-de-l-editeur';
    $editor->save();

    $engine->calls = [];

    $this->artisan('dolinews:translate', [
        '--editor' => $editor->slug,
        '--replay' => true,
    ])->assertSuccessful();

    expect($engine->calls)->toHaveCount(1)
        ->and($engine->calls[0]['target'])->toBe('it_IT');
});

it('replays the machine versions only, never a human translation', function (): void {
    $engine = translateCommandEngine();
    [$author, $editor] = translatingEditor();
    $source = Factory::publishedArticle($author);

    // Never sent anywhere, so there is nothing to send again.
    Factory::publishedTranslation($author, $source, 'nl_NL', ['title' => 'Traduction humaine']);
    machineVersion($author, $source, 'pl_PL');
    enableTranslation($editor);

    $engine->calls = [];

    $this->artisan('dolinews:translate', [
        '--article' => (string) $source->getKey(),
        '--replay' => true,
    ])->assertSuccessful();

    expect(array_column($engine->calls, 'target'))->toBe(['pl_PL']);
});

it('widens a replay to a language never sent when it is named', function (): void {
    $engine = translateCommandEngine();
    [$author, $editor] = translatingEditor();
    $source = Factory::publishedArticle($author);
    enableTranslation($editor);

    $engine->calls = [];

    $this->artisan('dolinews:translate', [
        '--article' => (string) $source->getKey(),
        '--locale' => ['ro_RO'],
        '--replay' => true,
    ])->assertSuccessful();

    expect(array_column($engine->calls, 'target'))->toBe(['ro_RO'])
        ->and(versionsOf($source))->toHaveCount(0);
});

it('sends nothing on a replay dry run', function (): void {
    $engine = translateCommandEngine();
    [$author, $editor] = translatingEditor();
    $source = Factory::publishedArticle($author);
    machineVersion($author, $source, 'pt_PT');
    enableTranslation($editor);

    $engine->calls = [];

    $this->artisan('dolinews:translate', [
        '--editor' => $editor->slug,
        '--replay' => true,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect($engine->calls)->toHaveCount(0);
});

it('refuses to replay for an editor that did not enable machine translation', function (): void {
    $engine = translateCommandEngine();
    [$author] = translatingEditor();
    $source = Factory::publishedArticle($author);
    machineVersion($author, $source, 'el_GR');

    // Nothing is published by a replay, but the text does leave for a
    // third party, which is what the editor agreed to.
    $engine->calls = [];

    $this->artisan('dolinews:translate', [
        '--article' => (string) $source->getKey(),
        '--replay' => true,
    ])->assertSuccessful();

    expect($engine->calls)->toHaveCount(0);
});

it('refuses a limit that is not a positive integer', function (): void {
    translateCommandEngine();
    [$author, $editor] = translatingEditor();
    $source = Factory::publishedArticle($author);
    enableTranslation($editor);

    $this->artisan('dolinews:translate', [
        '--editor' => $editor->slug,
        '--limit' => 'beaucoup',
    ])->assertFailed();

    expect(versionsOf($source))->toHaveCount(0);
});

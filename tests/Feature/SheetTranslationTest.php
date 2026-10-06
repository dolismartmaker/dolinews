<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\TranslationMandateService;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Projects\ProjectService;
use App\Domain\Dolinews\Translation\AutoProjectTranslationService;
use App\Domain\Dolinews\Translation\TranslationEngine;
use App\Models\User;
use Tests\Support\Factory;

/**
 * Project sheets, written and translated (SPEC 4.2, 5.6, 5.7).
 *
 * The sheet is the permanent half of what the service holds (D1), and
 * it used to be the half nobody could enrich by tool nor translate by
 * machine: a Spanish reader got the announcements in Spanish and the
 * project itself in French.
 */

/**
 * A sheet of an editor that asked for machine translation, with the
 * engine faked - what is under test is this service's own rules.
 *
 * @return array{0: User, 1: Project, 2: Editor}
 */
function sheetWithEngine(bool $optIn = true, bool $fails = false): array
{
    [$author, $editor] = Factory::contributorWithEditor();

    $engine = new class($fails) implements TranslationEngine
    {
        /** @var array<int, array<int, string>> */
        public array $batches = [];

        public function __construct(private readonly bool $fails) {}

        public function isAvailable(): bool
        {
            return true;
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

    $editor->auto_translate = $optIn;
    $editor->save();

    $project = app(ProjectService::class)->create($editor, [
        'name' => 'Facturx',
        'summary' => 'Métadonnées Factur-X dans les PDF de factures.',
        'description' => "## Présentation\n\nLe module intègre les métadonnées.\n\n```php\n\$conf->global->FACTURX_PROFILE = 'EN16931';\n```\n",
        'locale' => 'fr_FR',
    ]);

    return [$author, $project->refresh(), $editor];
}

it('writes the sheet in the languages it lacks, without translating its name', function (): void {
    [, $project] = sheetWithEngine();

    $written = app(AutoProjectTranslationService::class)->sync($project);

    $versions = $project->refresh()->translations;

    // Nine locales besides the source's.
    expect($written)->toBe(9)
        ->and($versions)->toHaveCount(9)
        ->and($versions->pluck('auto_translated')->unique()->all())->toBe([true]);

    $spanish = $versions->firstWhere('locale', 'es_ES');

    // The name of a module is a name: engines turn names into phrases,
    // so it travels untouched.
    expect($spanish?->name)->toBe('Facturx')
        ->and($spanish?->summary)->toBe('[es] Métadonnées Factur-X dans les PDF de factures.')
        ->and($spanish?->description)->toContain('[es] ## Présentation')
        // Fenced code is held out of the translation.
        ->and($spanish?->description)->toContain("\$conf->global->FACTURX_PROFILE = 'EN16931';")
        ->and($spanish?->description)->not->toContain('[es] ```php');
});

it('translates no sheet of an editor that did not ask for it', function (): void {
    [, $project] = sheetWithEngine(optIn: false);

    expect(app(AutoProjectTranslationService::class)->sync($project))->toBe(0)
        ->and($project->refresh()->translations)->toHaveCount(0);
});

it('never overwrites a sheet translation written by a person', function (): void {
    [, $project] = sheetWithEngine();

    app(ProjectService::class)->translate($project, 'es_ES', [
        'name' => 'Facturx',
        'summary' => 'Resumen escrito a mano.',
        'description' => 'Texto humano.',
        'auto_translated' => false,
    ]);

    app(AutoProjectTranslationService::class)->sync($project->refresh());

    $spanish = $project->refresh()->translations->firstWhere('locale', 'es_ES');

    expect($spanish?->summary)->toBe('Resumen escrito a mano.')
        ->and($spanish?->auto_translated)->toBeFalse();

    // On demand either: the button does not lift it.
    $again = app(AutoProjectTranslationService::class)->translateInto($project->refresh(), 'es_ES');

    expect($again)->toBeNull()
        ->and($project->refresh()->translations->firstWhere('locale', 'es_ES')?->summary)
        ->toBe('Resumen escrito a mano.');
});

it('rewrites its machine versions when the sheet itself has changed', function (): void {
    [, $project] = sheetWithEngine();

    app(AutoProjectTranslationService::class)->sync($project);

    $service = app(AutoProjectTranslationService::class);

    // Nothing to do on a sheet nobody touched: the sweep is free.
    expect($service->sync($project->refresh()))->toBe(0)
        ->and($service->outdatedTranslations($project->refresh()))->toHaveCount(0);

    app(ProjectService::class)->update($project, [
        'summary' => 'Résumé corrigé, bien plus précis.',
    ]);

    // A sheet has no revision number: the fingerprint of the text a
    // version was written against is what says it has moved on.
    expect($service->outdatedTranslations($project->refresh()))->toHaveCount(9);

    $written = $service->sync($project->refresh());

    expect($written)->toBe(9)
        ->and($project->refresh()->translations->firstWhere('locale', 'es_ES')?->summary)
        ->toBe('[es] Résumé corrigé, bien plus précis.');
});

it('leaves the sheet alone when the engine answers nothing', function (): void {
    [, $project] = sheetWithEngine(fails: true);

    expect(app(AutoProjectTranslationService::class)->sync($project))->toBe(0)
        ->and($project->refresh()->translations)->toHaveCount(0);
});

it('respects the languages the editor selected', function (): void {
    [, $project, $editor] = sheetWithEngine();

    $editor->translation_locales = ['es_ES', 'de_DE'];
    $editor->save();

    $written = app(AutoProjectTranslationService::class)->sync($project->refresh());

    expect($written)->toBe(2)
        ->and($project->refresh()->translations->pluck('locale')->sort()->values()->all())
        ->toBe(['de_DE', 'es_ES']);
});

it('runs the sheet sweep from the command line, and reports a dry run', function (): void {
    [, $project] = sheetWithEngine();

    $this->artisan('dolinews:translate-sheets', ['--project' => $project->slug, '--dry-run' => true])
        ->assertSuccessful();

    expect($project->refresh()->translations)->toHaveCount(0);

    $this->artisan('dolinews:translate-sheets', ['--project' => $project->slug])
        ->assertSuccessful();

    expect($project->refresh()->translations)->toHaveCount(9);

    // Idempotent: a second run writes nothing.
    $this->artisan('dolinews:translate-sheets', ['--project' => $project->slug])
        ->assertSuccessful();

    expect($project->refresh()->translations)->toHaveCount(9);
});

it('lets a mandated translator write a sheet translation through the API', function (): void {
    [$owner, $editor] = Factory::contributorWithEditor();

    $project = app(ProjectService::class)->create($editor, [
        'name' => 'Module mandaté',
        'summary' => 'Résumé de référence.',
        'locale' => 'fr_FR',
    ]);

    [$translator] = Factory::contributorWithEditor();

    $payload = [
        'locale' => 'es_ES',
        'name' => 'Module mandaté',
        'summary' => 'Resumen traducido por una persona.',
        'description' => 'Texto traducido.',
    ];

    // Without a mandate, a third-party contributor is refused.
    $this->withToken(tokenFor($translator))
        ->postJson('/api/v1/projects/'.$project->slug.'/translations', $payload)
        ->assertForbidden();

    app(TranslationMandateService::class)->grant($editor, $owner, $translator);

    // SPEC 5.6: a sheet is one of the two things a mandate covers, and
    // SPEC 14 names it as what a translation service would work on.
    $this->withToken(tokenFor($translator))
        ->postJson('/api/v1/projects/'.$project->slug.'/translations', $payload)
        ->assertCreated();

    expect($project->refresh()->translations->firstWhere('locale', 'es_ES')?->summary)
        ->toBe('Resumen traducido por una persona.')
        ->and($project->refresh()->translations->firstWhere('locale', 'es_ES')?->auto_translated)
        ->toBeFalse();
});

/**
 * A personal API token for the given account.
 */
function tokenFor(User $user): string
{
    return $user->createToken('tests')->plainTextToken;
}

it('offers the machine button on the sheet screen, and only where it answers', function (): void {
    [$author, $project, $editor] = sheetWithEngine();

    $screen = $this->actingAs($author)->get(route('account.projects.edit', $project));

    $screen->assertOk()
        ->assertSee(__('Traduire la fiche'))
        ->assertSee('name="locale"', false);

    $this->actingAs($author)
        ->post(route('account.projects.translations.auto', $project), ['locale' => 'de_DE'])
        ->assertRedirect(route('account.projects.edit', $project));

    $german = $project->refresh()->translations->firstWhere('locale', 'de_DE');

    expect($german?->summary)->toBe('[de] Métadonnées Factur-X dans les PDF de factures.')
        ->and($german?->auto_translated)->toBeTrue();

    // The editor did not opt in: the button must not be shown promising
    // something nobody will produce (SPEC 5.7).
    $editor->auto_translate = false;
    $editor->save();

    $this->actingAs($author)->get(route('account.projects.edit', $project))
        ->assertOk()
        ->assertDontSee(__('Traduire la fiche'));
});

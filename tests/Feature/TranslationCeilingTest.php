<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditEntry;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\TranslationUsage;
use App\Domain\Dolinews\Translation\TranslationEngine;
use App\Domain\Dolinews\Translation\TranslationRouter;
use App\Livewire\Admin\EditorList;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * The monthly ceiling of the shared translation route, per editor
 * (SPEC 5.7, amended 13 October 2026).
 *
 * The deployment default applies to every editor; the super admin may
 * set another one on an editor from the back-office, and clear it back
 * to the default. What was already spent this month stays counted.
 */
function bookTranslation(Editor $editor, int $characters): void
{
    TranslationUsage::query()->create([
        'editor_id' => $editor->getKey(),
        'period' => app(TranslationRouter::class)->period(),
        'characters' => $characters,
    ]);
}

/**
 * An available engine that is never called: the routing is under test,
 * not the translation.
 */
function standingEngine(): void
{
    app()->instance(TranslationEngine::class, new class implements TranslationEngine
    {
        public function isAvailable(): bool
        {
            return true;
        }

        public function translateBatch(array $texts, string $sourceLocale, string $targetLocale): ?array
        {
            return null;
        }

        public function supportedLocales(): array
        {
            return [];
        }
    });
}

it('applies the deployment default to an editor without a ceiling of its own', function (): void {
    config(['dolinews.translation.monthly_characters' => 5000]);
    [, $editor] = Factory::contributorWithEditor();

    $router = app(TranslationRouter::class);

    expect($router->ceiling($editor))->toBe(5000)
        ->and($router->remaining($editor))->toBe(5000);
});

it('routes on the shared engine past the default once the editor has a higher ceiling', function (): void {
    config(['dolinews.translation.monthly_characters' => 5000]);
    standingEngine();
    [, $editor] = Factory::contributorWithEditor();
    $editor->auto_translate = true;
    $editor->save();
    bookTranslation($editor, 5000);

    $router = app(TranslationRouter::class);

    expect($router->resolve($editor)['reason'])->toBe(TranslationRouter::REASON_QUOTA_SPENT);

    $editor->translation_monthly_characters = 20000;
    $editor->save();

    $route = $router->resolve($editor->refresh());

    expect($route['route'])->toBe(TranslationRouter::ROUTE_SHARED)
        ->and($router->remaining($editor))->toBe(15000);
});

it('lets the super admin set and clear an editor ceiling, journalled', function (): void {
    config(['dolinews.translation.monthly_characters' => 5000]);
    $admin = User::factory()->superAdmin()->create();
    [, $editor] = Factory::contributorWithEditor();

    $component = Livewire::actingAs($admin)
        ->test(EditorList::class)
        ->call('openEditor', $editor->getKey())
        ->set('editorTranslationCeiling', '250000')
        ->call('saveTranslationCeiling')
        ->assertHasNoErrors();

    expect($editor->refresh()->translation_monthly_characters)->toBe(250000)
        ->and(app(TranslationRouter::class)->ceiling($editor))->toBe(250000);

    $component->set('editorTranslationCeiling', '')
        ->call('saveTranslationCeiling')
        ->assertHasNoErrors();

    expect($editor->refresh()->translation_monthly_characters)->toBeNull()
        ->and(app(TranslationRouter::class)->ceiling($editor))->toBe(5000);

    $entries = AuditEntry::query()
        ->where('action', 'editor.translation_ceiling_changed')
        ->orderBy('id')
        ->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->meta)->toMatchArray(['previous' => null, 'ceiling' => 250000])
        ->and($entries[1]->meta)->toMatchArray(['previous' => 250000, 'ceiling' => null]);
});

it('refuses a negative or non-numeric ceiling', function (string $typed): void {
    $admin = User::factory()->superAdmin()->create();
    [, $editor] = Factory::contributorWithEditor();

    Livewire::actingAs($admin)
        ->test(EditorList::class)
        ->call('openEditor', $editor->getKey())
        ->set('editorTranslationCeiling', $typed)
        ->call('saveTranslationCeiling')
        ->assertHasErrors('editorTranslationCeiling');

    expect($editor->refresh()->translation_monthly_characters)->toBeNull();
})->with(['-1', 'beaucoup']);

it('keeps the ceiling out of a moderator\'s reach', function (): void {
    $moderator = User::factory()->moderator()->create();
    [, $editor] = Factory::contributorWithEditor();

    Livewire::actingAs($moderator)
        ->test(EditorList::class)
        ->set('editorId', $editor->getKey())
        ->set('editorTranslationCeiling', '250000')
        ->call('saveTranslationCeiling')
        ->assertForbidden();

    expect($editor->refresh()->translation_monthly_characters)->toBeNull();
});

it('tells an editor where to ask for a higher ceiling, above its own key', function (): void {
    config([
        'dolinews.translation.quota_contact' => 'quota@operator.test',
        'dolinews.operator_name' => 'Exploitant SA',
    ]);
    standingEngine();
    [$owner, $editor] = Factory::contributorWithEditor();

    $this->actingAs($owner)
        ->get(route('account.translations.automatic'))
        ->assertOk()
        ->assertSeeInOrder([
            'Demander un volume de traduction plus important',
            'Exploitant SA, qui exploite le service',
            'Votre clé DeepL',
        ])
        ->assertSee('href="mailto:quota@operator.test?subject=DoliNews%20-%20volume%20de%20traduction%20-%20'.$editor->slug.'"', false);
});

it('offers no ceiling request without a configured address', function (): void {
    config([
        'dolinews.translation.quota_contact' => '',
        'dolinews.operator_name' => 'Exploitant SA',
    ]);
    standingEngine();
    [$owner] = Factory::contributorWithEditor();

    $this->actingAs($owner)
        ->get(route('account.translations.automatic'))
        ->assertOk()
        ->assertDontSee('Demander un volume de traduction plus important');
});

it('offers no ceiling request to an editor on its own key', function (): void {
    config([
        'dolinews.translation.quota_contact' => 'quota@operator.test',
        'dolinews.operator_name' => 'Exploitant SA',
    ]);
    standingEngine();
    [$owner, $editor] = Factory::contributorWithEditor();
    $editor->translation_api_key = 'cle-deepl:fx';
    $editor->save();

    $this->actingAs($owner)
        ->get(route('account.translations.automatic'))
        ->assertOk()
        ->assertDontSee('Demander un volume de traduction plus important');
});

<?php

declare(strict_types=1);

use App\Domain\Dolinews\Enums\ModerationAction;
use App\Domain\Dolinews\Enums\ProjectStatus;
use App\Domain\Dolinews\Enums\ReportReason;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\ModerationLog;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Moderation\ModerationException;
use App\Domain\Dolinews\Moderation\ModerationService;
use App\Domain\Dolinews\Moderation\ReportService;
use App\Livewire\Admin\ProjectList;
use App\Livewire\Admin\ReportList;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * Masking and transferring a project sheet from the back-office
 * (SPEC 4.2, 9.1, 9.5, 9.9).
 */

/**
 * A sheet with one published announcement filed under it.
 *
 * @return array{0: Project, 1: Article, 2: User}
 */
function maskableSheet(): array
{
    $owner = User::factory()->create();
    $project = Factory::projectFor($owner, ['name' => 'Module Masquable']);
    $article = Factory::publishedArticle($owner, ['title' => 'Version 3.0 du module', 'project_id' => $project->getKey()]);

    return [$project, $article, $owner];
}

function hideFromPanel(User $actor, Project $project, bool $conflict = false): void
{
    Livewire::actingAs($actor)
        ->test(ProjectList::class)
        ->call('openAct', $project->getKey())
        ->assertSee($project->name)
        ->set('actKind', 'hide')
        ->set('actRule', 'R2')
        ->set('actMotive', 'Fiche usurpant le projet d\'un autre éditeur.')
        ->set('actConflict', $conflict)
        ->call('applyAct')
        ->assertHasNoErrors();
}

it('masks a sheet from its panel, journalled with its rule, the editor status untouched', function (): void {
    [$project] = maskableSheet();

    hideFromPanel(User::factory()->moderator()->create(), $project);

    $project->refresh();
    $log = ModerationLog::query()->where('project_id', $project->getKey())->firstOrFail();

    expect($project->isHidden())->toBeTrue()
        ->and($project->status)->toBe(ProjectStatus::ACTIVE)
        ->and($log->action)->toBe(ModerationAction::HIDDEN)
        ->and($log->rule_ref)->toBe('R2')
        ->and($log->article_id)->toBeNull();
});

it('takes a masked sheet out of every public surface', function (): void {
    [$project] = maskableSheet();
    hideFromPanel(User::factory()->superAdmin()->create(), $project);

    $this->get(route('projects.show', ['locale' => 'fr', 'slug' => $project->slug]))->assertNotFound();
    $this->get(route('editors.show', ['locale' => 'fr', 'slug' => $project->editor->slug]))
        ->assertOk()
        ->assertDontSee('Module Masquable');
    $this->get(route('home', ['locale' => 'fr', 'q' => 'Masquable']))->assertDontSee('Module Masquable');
    $this->get(route('sitemap.section', ['section' => 'projects']))->assertDontSee($project->slug);
    $this->getJson('/api/v1/projects')->assertJsonMissing(['slug' => $project->slug]);
    $this->getJson('/api/v1/projects/'.$project->slug)->assertNotFound();
});

it('serves a masked sheet to its own editor through the API, flagged', function (): void {
    [$project, , $owner] = maskableSheet();
    hideFromPanel(User::factory()->superAdmin()->create(), $project);

    // The panel left a web session behind, which Sanctum reads before the
    // token: drop it, an API client has none.
    $this->app['auth']->forgetGuards();

    $this->withToken(Factory::apiToken($owner))
        ->getJson('/api/v1/projects/'.$project->slug)
        ->assertOk()
        ->assertJsonPath('data.hidden', true);

    $this->app['auth']->forgetGuards();

    $this->withToken(Factory::apiToken(User::factory()->create()))
        ->getJson('/api/v1/projects/'.$project->slug)
        ->assertNotFound();
});

it('keeps the announcements of a masked sheet published, without a link to it', function (): void {
    [$project, $article] = maskableSheet();
    hideFromPanel(User::factory()->superAdmin()->create(), $project);

    $article->refresh();

    // Filed still: lifting the act files it back without anyone touching it.
    expect($article->project_id)->toBe($project->getKey())
        ->and($article->project)->toBeNull();

    $this->get(route('articles.show', ['locale' => 'fr', 'article' => $article]))
        ->assertOk()
        ->assertSee('Version 3.0 du module')
        ->assertDontSee('Module Masquable');
});

it('gives a masked sheet back, and its announcements are filed under it again', function (): void {
    [$project, $article] = maskableSheet();
    $moderator = User::factory()->moderator()->create();
    hideFromPanel($moderator, $project);

    Livewire::actingAs($moderator)
        ->test(ProjectList::class)
        ->call('openAct', $project->getKey())
        ->assertSet('actKind', 'unhide')
        ->set('actRule', 'R2')
        ->set('actMotive', 'Propriété établie par l\'éditeur, fiche rétablie.')
        ->call('applyAct')
        ->assertHasNoErrors();

    expect($project->fresh()?->isHidden())->toBeFalse()
        ->and($article->fresh()?->project?->getKey())->toBe($project->getKey());

    $this->get(route('projects.show', ['locale' => 'fr', 'slug' => $project->slug]))->assertOk();
});

it('lifts an unconfirmed masking taken in conflict of interest after seven days', function (): void {
    [$project] = maskableSheet();
    hideFromPanel(User::factory()->moderator()->create(), $project, conflict: true);

    $this->travelTo(now()->addDays(8));
    app(ModerationService::class)->expireUnconfirmed();

    expect($project->fresh()?->isHidden())->toBeFalse()
        ->and(ModerationLog::query()->where('project_id', $project->getKey())
            ->where('action', ModerationAction::UNHIDDEN->value)
            ->whereNull('moderator_user_id')
            ->exists())->toBeTrue();
});

it('masks a sheet from the report filed against it', function (): void {
    [$project] = maskableSheet();
    $report = app(ReportService::class)->reportProject($project, [
        'reason' => ReportReason::IMPERSONATION,
        'body' => 'Cette fiche reprend notre module sous un autre nom.',
        'email' => 'signalant@example.test',
        'locale' => 'fr_FR',
    ]);

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ReportList::class)
        ->call('openAct', $report->getKey())
        ->assertSet('actKind', 'hide')
        ->set('actRule', 'R2')
        ->set('actMotive', 'Usurpation de fiche établie par le signalement.')
        ->call('applyAct')
        ->assertHasNoErrors();

    expect($project->fresh()?->isHidden())->toBeTrue();
});

it('transfers a sheet to another editor, from the super admin panel only', function (): void {
    [$project] = maskableSheet();
    $claimant = Factory::editorFor(User::factory()->create());

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ProjectList::class)
        ->call('openAct', $project->getKey())
        ->set('transferEditorId', (string) $claimant->getKey())
        ->set('transferMotive', 'Propriété prouvée par le dépôt git du plaignant.')
        ->call('transfer')
        ->assertForbidden();

    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test(ProjectList::class)
        ->call('openAct', $project->getKey())
        ->set('transferEditorId', (string) $claimant->getKey())
        ->set('transferMotive', 'Propriété prouvée par le dépôt git du plaignant.')
        ->call('transfer')
        ->assertHasNoErrors();

    expect($project->fresh()?->editor_id)->toBe($claimant->getKey())
        ->and(ModerationLog::query()->where('action', ModerationAction::TRANSFERRED->value)->exists())->toBeTrue();
});

it('refuses a transfer to whoever belongs to either editor', function (): void {
    [$project, , $owner] = maskableSheet();
    $owner->forceFill(['is_super_admin' => true])->save();
    $claimant = Factory::editorFor(User::factory()->create());

    expect(fn () => app(ModerationService::class)->transferProject(
        $project,
        $claimant,
        $owner,
        'Transfert décidé par une partie au litige.',
    ))->toThrow(ModerationException::class);

    expect($project->fresh()?->editor_id)->not->toBe($claimant->getKey());
});

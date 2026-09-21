<?php

declare(strict_types=1);

use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\ProjectLink;
use App\Domain\Dolinews\Projects\ProjectService;
use App\Models\User;
use Tests\Support\Factory;

/**
 * Project sheets on the web (SPEC 4.2): the same ground as the API, for an
 * editor who does not want to write curl before naming their project.
 */
function sheetFor(User $user, array $overrides = []): Project
{
    $editor = Factory::editorFor($user);

    return app(ProjectService::class)->create($editor, array_merge([
        'name' => 'Module XY',
        'summary' => 'Gestion des relances clients pour Dolibarr.',
    ], $overrides));
}

it('lists the sheets of every editor the account belongs to', function (): void {
    $user = Factory::contributorWithoutEditor();
    sheetFor($user, ['name' => 'Module visible']);

    // Another contributor's sheet never shows up here.
    sheetFor(Factory::contributorWithoutEditor(), ['name' => 'Module de quelqu un d autre']);

    $this->actingAs($user)->get(route('account.projects'))
        ->assertOk()
        ->assertSee('Module visible')
        ->assertDontSee('Module de quelqu un d autre');
});

it('creates a sheet from the account', function (): void {
    $user = Factory::contributorWithoutEditor();
    $editor = Factory::editorFor($user);

    $this->actingAs($user)->post(route('account.projects.store'), [
        'editor_id' => $editor->getKey(),
        'name' => 'Module ZZ',
        'summary' => 'Ce que fait le module, en une phrase.',
        'license' => 'GPL-3.0-or-later',
    ])->assertRedirect();

    $project = Project::query()->where('name', 'Module ZZ')->firstOrFail();

    expect($project->editor_id)->toBe($editor->getKey())
        ->and($project->slug)->toBe('module-zz')
        // The sheet is persistent, so it carries nothing dated (SPEC D1).
        ->and($project->getAttributes())->not->toHaveKey('dolibarr_min');
});

it('refuses a sheet for an editor the account does not belong to', function (): void {
    $user = Factory::contributorWithoutEditor();
    $stranger = Factory::editorFor(Factory::contributorWithoutEditor());

    $this->actingAs($user)->post(route('account.projects.store'), [
        'editor_id' => $stranger->getKey(),
        'name' => 'Fiche volée',
        'summary' => 'Tentative de rattachement à un éditeur tiers.',
    ])->assertForbidden();

    expect(Project::query()->where('name', 'Fiche volée')->exists())->toBeFalse();
});

it('refuses the whole screen to someone who is not a member of the editor', function (): void {
    $owner = Factory::contributorWithoutEditor();
    $project = sheetFor($owner);
    $outsider = Factory::contributorWithoutEditor();

    $this->actingAs($outsider)->get(route('account.projects.edit', $project))->assertForbidden();
    $this->actingAs($outsider)->patch(route('account.projects.update', $project), [
        'name' => 'Renommé de force',
        'summary' => 'Ne doit pas passer.',
        'status' => 'active',
    ])->assertForbidden();
});

it('updates the fields of a sheet, status included', function (): void {
    $user = Factory::contributorWithoutEditor();
    $project = sheetFor($user);

    $this->actingAs($user)->patch(route('account.projects.update', $project), [
        'name' => 'Module XY renommé',
        'summary' => 'Un résumé corrigé.',
        'description' => 'Le détail du module.',
        'license' => 'MIT',
        // An unmaintained sheet that says so stays honest (SPEC 4.2).
        'status' => 'unmaintained',
    ])->assertRedirect();

    $project->refresh();

    expect($project->name)->toBe('Module XY renommé')
        ->and($project->status->value)->toBe('unmaintained')
        // The slug is the public identifier: renaming the sheet never moves it.
        ->and($project->slug)->toBe('module-xy');
});

it('adds and removes a typed link', function (): void {
    $user = Factory::contributorWithoutEditor();
    $project = sheetFor($user);

    $this->actingAs($user)->post(route('account.projects.links', $project), [
        'type' => 'repo',
        'url' => 'https://git.example.org/acme/module-xy',
        'label' => 'Dépôt git',
    ])->assertRedirect();

    $link = ProjectLink::query()->where('project_id', $project->getKey())->firstOrFail();
    expect($link->type->value)->toBe('repo');

    $this->actingAs($user)
        ->delete(route('account.projects.links.destroy', [$project, $link->getKey()]))
        ->assertRedirect();

    expect(ProjectLink::query()->whereKey($link->getKey())->exists())->toBeFalse();
});

it('refuses a shortened link with its reason on the form', function (): void {
    // Shorteners are refused, no exception (D8): the destination has to be
    // readable before the click.
    $user = Factory::contributorWithoutEditor();
    $project = sheetFor($user);

    $this->actingAs($user)->post(route('account.projects.links', $project), [
        'type' => 'doc',
        'url' => 'https://bit.ly/abcdef',
    ])->assertRedirect()->assertSessionHasErrors('url');

    expect(ProjectLink::query()->where('project_id', $project->getKey())->exists())->toBeFalse();
});

it('stores a translation of the sheet and replaces it on the same locale', function (): void {
    $user = Factory::contributorWithoutEditor();
    $project = sheetFor($user);

    $this->actingAs($user)->post(route('account.projects.translations', $project), [
        'locale' => 'en_US',
        'name' => 'Module XY',
        'summary' => 'Customer follow-up for Dolibarr.',
    ])->assertRedirect();

    $this->actingAs($user)->post(route('account.projects.translations', $project), [
        'locale' => 'en_US',
        'name' => 'Module XY',
        'summary' => 'Dunning management for Dolibarr.',
    ])->assertRedirect();

    $translations = $project->fresh()?->translations;

    expect($translations)->toHaveCount(1)
        ->and($translations?->first()?->summary)->toBe('Dunning management for Dolibarr.');
});

it('offers the tab from every screen of the account', function (): void {
    $user = Factory::contributorWithoutEditor();

    $this->actingAs($user)->get(route('account.show'))
        ->assertOk()
        ->assertSee(route('account.projects'));
});

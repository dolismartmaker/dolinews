<?php

declare(strict_types=1);

use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Models\User;
use Tests\Support\Factory;

/**
 * Where a reader can actually subscribe (SPEC 6.4).
 *
 * The head of the feed names both channels, but the intention is born
 * on a sheet: someone reading about one module wants that module, not
 * the whole service. The cards used to sit behind @auth and the feeds
 * filtered by project or editor existed without being exposed
 * anywhere, so a visitor had the site-wide feed of the footer and
 * nothing else - the integrator D11 exists for was served last.
 */
function sheetWithAnnouncement(string $title = 'Module suivi 1.0'): Project
{
    $author = User::factory()->create();
    $editor = Factory::editorFor($author);

    /** @var Project $project */
    $project = Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'module-suivi-'.substr(uniqid(), -5),
        'name' => 'Module suivi',
        'summary' => 'Fiche du module suivi.',
        'status' => 'active',
    ]);

    Factory::publishedArticle($author, [
        'title' => $title,
        'project_id' => $project->getKey(),
    ]);

    return $project;
}

it('offers the subscription of a sheet to a visitor with no account', function (): void {
    $project = sheetWithAnnouncement();

    $this->get(route('projects.show', ['slug' => $project->slug]))
        ->assertOk()
        ->assertSee(__('Suivre ce projet'))
        ->assertSee(route('register'))
        ->assertSee(route('feeds.rss', ['project' => $project->slug]), escape: false);
});

it('offers the subscription of an editor to a visitor with no account', function (): void {
    $project = sheetWithAnnouncement();

    /** @var Editor $editor */
    $editor = $project->editor;

    $this->get(route('editors.show', ['slug' => $editor->slug]))
        ->assertOk()
        ->assertSee(__('Suivre cet éditeur'))
        ->assertSee(route('feeds.rss', ['editor' => $editor->slug]), escape: false);
});

it('declares the feed of a sheet to feed readers', function (): void {
    $project = sheetWithAnnouncement();

    $this->get(route('projects.show', ['slug' => $project->slug]))
        ->assertOk()
        ->assertSee('<link rel="alternate" type="application/rss+xml"', escape: false);
});

it('answers that feed with the announcements of that project alone', function (): void {
    $project = sheetWithAnnouncement();

    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module ignoré 1.0']);

    $feed = $this->get(route('feeds.rss', ['project' => $project->slug]));

    $feed->assertOk();

    expect($feed->getContent())->toContain('Module suivi 1.0')
        ->and($feed->getContent())->not->toContain('Module ignoré 1.0');
});

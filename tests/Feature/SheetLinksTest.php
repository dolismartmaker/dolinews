<?php

declare(strict_types=1);

use App\Domain\Dolinews\Enums\LinkType;
use App\Domain\Dolinews\Projects\ProjectService;
use App\Models\User;
use Tests\Support\Factory;

/**
 * The links box of a public sheet reads as words, not as stored codes.
 */
it('names each link by its kind and shows where it goes', function (): void {
    $project = Factory::projectFor(User::factory()->create());
    $service = app(ProjectService::class);
    $service->addLink($project, LinkType::REPO, 'https://github.com/acme/module');
    $service->addLink($project, LinkType::DOC, 'https://www.acme.example/doc', 'Guide d\'installation');

    $this->get(route('projects.show', ['locale' => 'fr', 'slug' => $project->slug]))
        ->assertOk()
        ->assertSee('Code source')
        ->assertSee('github.com')
        // An author's label wins; the pictogram shows the kind, and a
        // screen reader still hears it.
        ->assertSee('Guide d&#039;installation', false)
        ->assertSee('<span class="sr-only">(Documentation)</span>', false)
        ->assertSee('acme.example')
        ->assertDontSee('>repo<', false);
});

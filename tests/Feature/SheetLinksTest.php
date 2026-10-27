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

it('carries the sheet links in the project column of an announcement', function (): void {
    // The reader decides to buy or try where they understood the
    // announcement concerns them; the links stay those of the sheet
    // (SPEC 1.1, 8).
    $author = User::factory()->create();
    $project = Factory::projectFor($author);
    $service = app(ProjectService::class);
    $service->addLink($project, LinkType::DOLISTORE, 'https://www.dolistore.com/fr/modules/1234-module-xy.html');
    $service->addLink($project, LinkType::DEMO, 'https://demo.acme.example');

    $article = Factory::publishedArticle($author, ['project_id' => $project->getKey()]);

    $this->get('/fr/articles/'.$article->getKey())->assertOk()
        ->assertSee('https://www.dolistore.com/fr/modules/1234-module-xy.html', false)
        ->assertSee('https://demo.acme.example', false)
        ->assertSee('rel="nofollow ugc"', false);
});

it('leaves the announcement column without links when the sheet has none or is hidden', function (): void {
    $author = User::factory()->create();
    $project = Factory::projectFor($author);
    $article = Factory::publishedArticle($author, ['project_id' => $project->getKey()]);

    // No empty box on an announcement: "no link declared" belongs to the sheet.
    $this->get('/fr/articles/'.$article->getKey())->assertOk()
        ->assertDontSee('Aucun lien déclaré.');

    app(ProjectService::class)->addLink($project, LinkType::DOLISTORE, 'https://www.dolistore.com/fr/modules/1234-module-xy.html');
    $project->forceFill(['hidden_at' => now()])->save();

    // A hidden sheet answers as an absent one (SPEC 4.2).
    $this->get('/fr/articles/'.$article->getKey())->assertOk()
        ->assertDontSee('dolistore.com/fr/modules/1234', false);
});

it('takes a link to the test reports through the API and names it on the sheet', function (): void {
    [$user, $editor] = Factory::contributorWithEditor();
    $project = app(ProjectService::class)
        ->create($editor, ['name' => 'Module XY', 'summary' => 'Un module.']);

    $this->withToken(Factory::apiToken($user))
        ->postJson('/api/v1/projects/'.$project->slug.'/links', [
            'type' => 'tests',
            'url' => 'https://captests.acme.example/projets/module-xy',
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'tests');

    // A type the enum does not know is refused, not stored.
    $this->withToken(Factory::apiToken($user))
        ->postJson('/api/v1/projects/'.$project->slug.'/links', [
            'type' => 'quality',
            'url' => 'https://acme.example/q',
        ])
        ->assertStatus(422);

    $this->get(route('projects.show', ['locale' => 'fr', 'slug' => $project->slug]))
        ->assertOk()
        ->assertSee('Rapports de tests')
        ->assertSee('captests.acme.example');
});

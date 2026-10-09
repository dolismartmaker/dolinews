<?php

declare(strict_types=1);

use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Projects\ProjectService;
use App\Domain\Dolinews\Seo\ArticleUrl;
use App\Models\User;
use Tests\Support\Factory;

/**
 * What a project sheet holds and how it reads (SPEC 4.2).
 *
 * The sheet is the permanent half of what the service carries (D1), and
 * it used to be the half nobody could enrich by tool: the catalogue
 * import wrote it from one sentence of a module descriptor, the API
 * could create a sheet but never correct it, and the description came
 * out as one block of raw text.
 */
it('corrects a sheet through the API without blanking what it did not send', function (): void {
    [$owner, $editor] = Factory::contributorWithEditor();

    $project = app(ProjectService::class)->create($editor, [
        'name' => 'Module à enrichir',
        'summary' => 'Résumé initial.',
        'description' => 'Une phrase.',
        'license' => 'GPL-3.0-or-later',
        'locale' => 'fr_FR',
    ]);

    $response = $this->withToken(sheetTokenFor($owner))
        ->patchJson('/api/v1/projects/'.$project->slug, [
            'description' => "## Présentation\n\nUn texte bien plus utile.\n\n- une fonctionnalité\n- une autre",
        ]);

    $response->assertOk();

    $project->refresh();

    expect($project->description)->toContain('## Présentation')
        // What the call did not carry is left as it was.
        ->and($project->summary)->toBe('Résumé initial.')
        ->and($project->license)->toBe('GPL-3.0-or-later')
        // The rendered form travels with it, so a client shows the sheet
        // without running a Markdown renderer of its own.
        ->and($response->json('data.description_html'))->toContain('<h2>');
});

it('refuses a description longer than the instance allows', function (): void {
    [$owner, $editor] = Factory::contributorWithEditor();

    $project = app(ProjectService::class)->create($editor, [
        'name' => 'Module bavard',
        'summary' => 'Résumé.',
        'locale' => 'fr_FR',
    ]);

    // A sheet presents a project, it does not document it: the manual
    // lives behind the sheet's doc link.
    $this->withToken(sheetTokenFor($owner))
        ->patchJson('/api/v1/projects/'.$project->slug, [
            'description' => str_repeat('a', (int) config('dolinews.projects.description_max') + 1),
        ])
        ->assertStatus(422);
});

it('shows the sheet as Markdown and says when it is not in the reader language', function (): void {
    [, $editor] = Factory::contributorWithEditor();

    $project = app(ProjectService::class)->create($editor, [
        'name' => 'Module lisible',
        'summary' => 'Résumé de référence.',
        'description' => "## Présentation\n\nUn paragraphe.\n\n- premier point\n- second point",
        'locale' => 'fr_FR',
    ]);

    $french = $this->get('/fr/projets/'.$project->slug);

    $french->assertOk()
        ->assertSee('<h2>Présentation</h2>', false)
        ->assertSee('<li>premier point</li>', false);

    // Read in Spanish with no Spanish version: shown rather than hidden,
    // and the reader is told which language it is in (SPEC 6.1).
    $spanish = $this->get('/es/projets/'.$project->slug);

    $spanish->assertOk()
        ->assertSee('Résumé de référence.')
        ->assertSee(__('en', [], 'es').' Français');

    app(ProjectService::class)->translate($project, 'es_ES', [
        'name' => 'Module lisible',
        'summary' => 'Resumen en español.',
        'description' => '## Presentación',
    ]);

    $this->get('/es/projets/'.$project->slug)
        ->assertOk()
        ->assertSee('Resumen en español.')
        ->assertDontSee(__('en', [], 'es').' Français');
});

it('serves the Greek version of a sheet to a Greek reader', function (): void {
    [, $editor] = Factory::contributorWithEditor();

    $project = app(ProjectService::class)->create($editor, [
        'name' => 'Module grec',
        'summary' => 'Résumé de référence.',
        'locale' => 'fr_FR',
    ]);

    app(ProjectService::class)->translate($project, 'el_GR', [
        'name' => 'Module grec',
        'summary' => 'Ελληνική περίληψη.',
        'description' => null,
    ]);

    // The content locale used to be built by hand from the interface
    // one, which answered el_EL: the Greek sheet existed and no Greek
    // reader ever saw it.
    $this->get('/el/projets/'.$project->slug)
        ->assertOk()
        ->assertSee('Ελληνική περίληψη.');
});

it('names the newest stable version announced, with its date', function (): void {
    $author = Factory::contributorWithoutEditor();
    $project = Factory::projectFor($author);

    $announce = function (string $version, string $maturity, int $daysAgo) use ($author, $project): Article {
        $article = Factory::publishedArticle($author, [
            'title' => 'Module XY '.$version,
            'version' => $version,
            'maturity' => $maturity,
        ]);

        $article->forceFill([
            'project_id' => $project->getKey(),
            'published_at' => now()->subDays($daysAgo)->startOfDay(),
        ])->save();

        return $article;
    };

    $announce('2.0.0', 'stable', 30);
    $newest = $announce('2.1.0', 'stable', 10);
    // A newer beta is not what an integrator installs (SPEC 6.2).
    $announce('3.0.0-beta1', 'beta', 2);

    $date = $newest->published_at?->locale('fr')->isoFormat('LL');

    $this->get('/fr/projets/'.$project->slug)
        ->assertOk()
        ->assertSeeInOrder(['Dernière version stable annoncée :', '2.1.0', 'le '.$date])
        ->assertDontSee('Dernière version stable annoncée : 3.0.0-beta1');

    // The reader is sent to the announcement in their language when it
    // has one, as the feed would (SPEC 6.1).
    $english = Factory::publishedTranslation($author, $newest, 'en_US');

    $this->get('/en/projets/'.$project->slug)
        ->assertOk()
        ->assertSee(__('Dernière version stable annoncée :', [], 'en'))
        ->assertSee(ArticleUrl::for($english), false);
});

it('says nothing of a version when no stable one was announced', function (): void {
    $author = Factory::contributorWithoutEditor();
    $project = Factory::projectFor($author);

    $article = Factory::publishedArticle($author, ['version' => '1.0.0-rc1', 'maturity' => 'rc']);
    $article->forceFill(['project_id' => $project->getKey()])->save();

    $this->get('/fr/projets/'.$project->slug)
        ->assertOk()
        ->assertDontSee('Dernière version stable annoncée');
});

/**
 * A personal API token for the given account.
 */
function sheetTokenFor(User $user): string
{
    return $user->createToken('tests')->plainTextToken;
}

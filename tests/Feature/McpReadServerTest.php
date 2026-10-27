<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Attestations\AttestationService;
use App\Mcp\DolinewsServer;
use App\Mcp\ReadingPayload;
use App\Mcp\Tools\FindProjects;
use App\Mcp\Tools\GetAnnouncement;
use App\Mcp\Tools\GetEditor;
use App\Mcp\Tools\GetProject;
use App\Mcp\Tools\SearchAnnouncements;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\Support\Factory;

/**
 * The MCP read server (SPEC 6.7): anonymous, read-only, the reads of a
 * visitor and their rules - one entry per announcement, stable by
 * default, the announced floor, a masked sheet absent - with D1 carried
 * in every result.
 */
function mcpData(TestResponse $response): array
{
    $data = [];

    $response->assertOk()->assertStructuredContent(function (AssertableJson $json) use (&$data): void {
        $data = $json->toArray();
        $json->etc();
    });

    return $data;
}

/**
 * One JSON-RPC call over the HTTP transport, as a remote client sends it.
 */
function mcpPost(string $method, array $params = []): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params,
    ], ['Accept' => 'application/json, text/event-stream']);
}

it('answers an anonymous client over HTTP and lists read-only tools only', function (): void {
    mcpPost('initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => (object) [],
        'clientInfo' => ['name' => 'test', 'version' => '1'],
    ])
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', config('app.name'));

    $tools = collect(mcpPost('tools/list')->assertOk()->json('result.tools'));

    expect($tools->pluck('name')->sort()->values()->all())->toBe([
        'find_projects',
        'get_announcement',
        'get_editor',
        'get_project',
        'search_announcements',
    ]);

    // No tool writes, and none asks whether a module is compatible (D1).
    $tools->each(function (array $tool): void {
        expect($tool['annotations']['readOnlyHint'] ?? false)->toBeTrue();
        expect($tool['name'])->not->toContain('compatib');
    });
});

it('calls a tool over HTTP without any token', function (): void {
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Annonce publique']);

    mcpPost('tools/call', ['name' => 'search_announcements', 'arguments' => (object) []])
        ->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.announcements.0.title', 'Annonce publique');
});

it('carries the licence and the reminder of D1 in every result', function (): void {
    $data = mcpData(DolinewsServer::tool(SearchAnnouncements::class, []));

    expect($data['license'])->toBe('CC BY-SA 4.0')
        ->and($data['notice'])->toBe(ReadingPayload::NOTICE);
});

it('returns stable announcements only unless a maturity is named', function (): void {
    $author = User::factory()->create();
    Factory::publishedArticle($author, ['title' => 'Version stable']);
    Factory::publishedArticle($author, ['title' => 'Version beta', 'maturity' => 'beta']);

    $default = mcpData(DolinewsServer::tool(SearchAnnouncements::class, []));
    expect(collect($default['announcements'])->pluck('title')->all())->toBe(['Version stable']);

    $beta = mcpData(DolinewsServer::tool(SearchAnnouncements::class, ['maturity' => ['beta']]));
    expect(collect($beta['announcements'])->pluck('title')->all())->toBe(['Version beta']);
});

it('never serves an announcement that is not published', function (): void {
    $author = User::factory()->create();
    Factory::article($author, ['title' => 'Brouillon']);
    $pending = Factory::article($author, ['title' => 'En revue']);
    app(ArticleService::class)->submit($pending, $author);

    $data = mcpData(DolinewsServer::tool(SearchAnnouncements::class, []));

    expect($data['total'])->toBe(0);

    DolinewsServer::tool(GetAnnouncement::class, ['id' => $pending->getKey()])
        ->assertHasErrors(['No published announcement has the id '.$pending->getKey().'.']);
});

it('serves one entry per announcement, in the reader language when it exists', function (): void {
    $author = User::factory()->create();
    $translated = Factory::publishedArticle($author, ['title' => 'Version française']);
    Factory::publishedTranslation($author, $translated, 'es_ES', ['title' => 'Versión española']);
    Factory::publishedArticle($author, ['title' => 'Jamais traduite']);

    $data = mcpData(DolinewsServer::tool(SearchAnnouncements::class, ['locale' => 'es']));
    $titles = collect($data['announcements'])->pluck('title');

    expect($titles->sort()->values()->all())->toBe(['Jamais traduite', 'Versión española'])
        ->and($data['total'])->toBe(2);

    // What nobody translated says its language, in the reader's (SPEC 6.1).
    $untranslated = collect($data['announcements'])->firstWhere('title', 'Jamais traduite');
    expect($untranslated['labels']['language'])->not->toBeNull()
        ->and($untranslated['locale'])->toBe('fr_FR');
});

it('dates every announcement and writes its range short, without the generator floor', function (): void {
    $author = User::factory()->create();
    Factory::publishedArticle($author, ['title' => 'Plage saisie', 'dolibarr_min' => 18, 'dolibarr_max' => 24]);
    Factory::publishedArticle($author, ['title' => 'Plancher du générateur', 'dolibarr_min' => 11]);

    $data = mcpData(DolinewsServer::tool(SearchAnnouncements::class, ['locale' => 'en']));
    $byTitle = collect($data['announcements'])->keyBy('title');

    expect($byTitle['Plage saisie']['labels']['dolibarr'])->toBe('Dolibarr 18 to 24')
        ->and($byTitle['Plage saisie']['announced_on'])->toBe(now()->format('Y-m-d'))
        ->and($byTitle['Plage saisie']['labels']['announced_age'])->not->toBeNull()
        // A floor nobody chose was never announced (SPEC 6.1).
        ->and($byTitle['Plancher du générateur']['dolibarr_min'])->toBeNull()
        ->and($byTitle['Plancher du générateur']['labels']['dolibarr'])->toBeNull();
});

it('says why a search is empty and names the matching sheets', function (): void {
    $empty = mcpData(DolinewsServer::tool(SearchAnnouncements::class, ['query' => 'introuvable']));

    expect($empty['announcements'])->toBe([])
        ->and($empty['empty_reason'])->toContain('may exist without being announced');

    $owner = User::factory()->create();
    Factory::projectFor($owner, ['name' => 'Pointeuse Atelier']);

    $data = mcpData(DolinewsServer::tool(SearchAnnouncements::class, ['query' => 'pointeuse']));

    expect(collect($data['matching_projects'])->pluck('name')->all())->toBe(['Pointeuse Atelier']);
});

it('reads one announcement with the tests its editor published, never as a certification', function (): void {
    $owner = User::factory()->create();
    $project = Factory::projectFor($owner);
    $article = Factory::publishedArticle($owner, ['project_id' => $project->getKey(), 'body' => "## Nouveau\n\nTexte."]);

    app(AttestationService::class)->record($project, [
        'source_type' => 'ci',
        'source_url' => 'https://ci.exemple.test/run/1',
        'metric' => 'cas',
        'value' => '42',
    ], $article);

    $data = mcpData(DolinewsServer::tool(GetAnnouncement::class, ['id' => $article->getKey(), 'locale' => 'en']));

    expect($data['body_markdown'])->toBe("## Nouveau\n\nTexte.")
        ->and($data['project_url'])->toContain($project->slug)
        ->and($data['attestations'])->toHaveCount(1)
        ->and($data['attestations'][0]['label'])->toBe('Tests published by the editor')
        ->and($data['attestations'][0]['declared_by_editor'])->toBeTrue()
        ->and($data['attestations'][0]['report_url'])->toBe('https://ci.exemple.test/run/1');
});

it('reads a sheet with the newest stable version announced and no compatibility', function (): void {
    $owner = User::factory()->create();
    $project = Factory::projectFor($owner, ['name' => 'Relances']);
    Factory::publishedArticle($owner, ['project_id' => $project->getKey(), 'version' => '2.3', 'title' => 'Relances 2.3']);
    Factory::publishedArticle($owner, ['project_id' => $project->getKey(), 'version' => '3.0-beta', 'maturity' => 'beta', 'title' => 'Relances 3.0 beta']);

    $data = mcpData(DolinewsServer::tool(GetProject::class, ['slug' => $project->slug]));

    expect($data['name'])->toBe('Relances')
        ->and($data['latest_stable_announced']['version'])->toBe('2.3')
        ->and($data['latest_stable_announced']['announced_on'])->toBe(now()->format('Y-m-d'))
        // The sheet lists every maturity: the project is the whole subject.
        ->and(collect($data['recent_announcements'])->pluck('title')->sort()->values()->all())
        ->toBe(['Relances 2.3', 'Relances 3.0 beta'])
        ->and($data)->not->toHaveKeys(['dolibarr_min', 'dolibarr_max']);
});

it('answers for a masked sheet as for an absent one', function (): void {
    $owner = User::factory()->create();
    $project = Factory::projectFor($owner, ['name' => 'Fiche masquée']);
    $project->forceFill(['hidden_at' => now()])->save();

    DolinewsServer::tool(GetProject::class, ['slug' => $project->slug])
        ->assertHasErrors(['No project sheet has the slug "'.$project->slug.'". Use find_projects to look for it.']);

    $data = mcpData(DolinewsServer::tool(FindProjects::class, []));

    expect(collect($data['projects'])->pluck('name')->all())->not->toContain('Fiche masquée');
});

it('lists the sheets of one editor and never serves its contact address', function (): void {
    $owner = User::factory()->create();
    $editor = Factory::editorFor($owner);
    Factory::projectFor($owner, ['name' => 'Alpha']);
    Factory::projectFor($owner, ['name' => 'Beta']);
    Factory::projectFor(User::factory()->create(), ['name' => 'Autre éditeur']);

    $listed = mcpData(DolinewsServer::tool(FindProjects::class, ['editor' => $editor->slug]));

    expect(collect($listed['projects'])->pluck('name')->all())->toBe(['Alpha', 'Beta']);

    $response = DolinewsServer::tool(GetEditor::class, ['slug' => $editor->slug]);
    $data = mcpData($response);

    expect($data['name'])->toBe($editor->name)
        ->and(collect($data['projects'])->pluck('name')->all())->toBe(['Alpha', 'Beta'])
        ->and($data)->not->toHaveKey('contact_email');

    $response->assertDontSee($editor->contact_email);
});

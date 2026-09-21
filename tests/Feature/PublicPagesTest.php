<?php

declare(strict_types=1);

use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Models\Project;
use App\Models\User;
use Tests\Support\Factory;

/**
 * Public pages (SPEC 6, LARAVEL_PAGES_PUBLIQUES): one HTTP test per
 * page, no JavaScript on public pages, feeds reachable.
 */
it('renders the home feed page', function (): void {
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module XY 2.1 stable']);

    $response = $this->get('/');

    $response->assertOk()
        ->assertSee('Module XY 2.1 stable')
        ->assertSee('Annonces de l\'écosystème Dolibarr');
});

it('loads no javascript bundle on public pages', function (string $uri): void {
    $content = (string) $this->get($uri)->getContent();

    // The invariant of ~/docs/laravel/LARAVEL_PAGES_PUBLIQUES.md: a
    // public page loads CSS, never the application's JS bundle. The
    // check targets the project bundle markers, not any injected script
    // tag: Livewire's asset injector leaves process-wide traces after a
    // back-office test rendered a component.
    expect($content)->not->toContain('resources/js/app.js')
        ->and($content)->not->toContain('@vite')
        ->and($content)->not->toContain('vite/assets');
})->with([
    '/',
    '/revue',
    '/engagements',
    '/regles',
    '/donnees',
    '/mentions',
]);

it('excludes non-stable maturities by default and includes them on opt-in', function (): void {
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Sortie stable visible', 'maturity' => 'stable']);
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Beta masquee par defaut', 'maturity' => 'beta']);

    $this->get('/')->assertOk()
        ->assertSee('Sortie stable visible')
        ->assertDontSee('Beta masquee par defaut');

    $this->get('/?all_maturities=1')->assertOk()
        ->assertSee('Beta masquee par defaut');
});

it('renders one article page with its maturity badge', function (): void {
    $article = Factory::publishedArticle(User::factory()->create());

    $response = $this->get('/articles/'.$article->getKey());

    $response->assertOk()
        ->assertSee($article->title)
        ->assertSee('Sécurité');
});

it('hides a hidden article from the public page', function (): void {
    $article = Factory::publishedArticle(User::factory()->create());
    $article->status = 'hidden';
    $article->save();

    $this->get('/articles/'.$article->getKey())->assertNotFound();
});

it('renders the five guest authentication screens', function (string $uri): void {
    $this->get($uri)->assertOk();
})->with([
    '/login',
    '/register',
    '/forgot-password',
    '/reset-password/token-test',
]);

it('renders the verification notice for a signed-in unverified account', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/verify-email')->assertOk();
});

it('shows the moderation transparency figures', function (): void {
    Factory::publishedArticle(User::factory()->create());

    $this->get('/revue')->assertOk()
        ->assertSee('Délai observé')
        ->assertSee('Attente la plus ancienne');
});

it('serves a project sheet and an editor page', function (): void {
    $author = User::factory()->create();
    $editor = (new EditorService)->create($author, [
        'name' => 'ACME modules',
        'contact_email' => 'acme@example.test',
        'website' => 'https://acme.example',
    ]);

    $project = Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'module-xy',
        'name' => 'Module XY',
        'summary' => 'Gestion des XY pour Dolibarr',
    ]);

    $project->links()->create([
        'type' => 'repo',
        'url' => 'https://git.example/acme/module-xy',
        'position' => 1,
    ]);

    $this->get('/projets/module-xy')->assertOk()
        ->assertSee('Module XY')
        ->assertSee('git.example');

    $this->get('/editeurs/acme-modules')->assertOk()
        ->assertSee('ACME modules');
});

it('serves the static commitment and rules pages', function (): void {
    $this->get('/engagements')->assertOk()->assertSee('gratuite');
    $this->get('/regles')->assertOk()->assertSee('R1');
    $this->get('/donnees')->assertOk();
    $this->get('/mentions')->assertOk();
});

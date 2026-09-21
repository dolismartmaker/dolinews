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
    '/guide-editeur',
    // The API documentation is rendered server-side for this very
    // reason: the usual specification viewers are all JavaScript.
    '/documentation-api',
]);

it('excludes non-stable maturities by default and includes them by name', function (): void {
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Sortie stable visible', 'maturity' => 'stable']);
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Beta masquee par defaut', 'maturity' => 'beta']);

    $this->get('/')->assertOk()
        ->assertSee('Sortie stable visible')
        ->assertDontSee('Beta masquee par defaut');

    // Named maturity, the only opt-in left: the blanket checkbox is gone.
    $this->get('/?maturity[]=beta')->assertOk()
        ->assertSee('Beta masquee par defaut');

    $this->get('/?all_maturities=1')->assertOk()
        ->assertDontSee('Beta masquee par defaut');
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

it('walks an editor from the account to the first submission', function (): void {
    $response = $this->get('/guide-editeur');

    $response->assertOk()
        // The two gates an editor hits first, in order. Expectations are
        // escaped like Blade escapes them: the apostrophes come out as
        // entities in the rendered page.
        ->assertSee('Prouver votre contribution')
        ->assertSee('Créer un jeton d\'API')
        // The way out when no reference repository knows the address:
        // without it, an editor without a public repo reads a dead end.
        ->assertSee('validation manuelle')
        // The sheet is API-only today: the guide says so and shows the call.
        ->assertSee('/api/v1/projects')
        // A token submits, it never publishes (SPEC 5.2).
        ->assertSee('jamais celui de publier');
});

it('reaches the editor guide from the public navigation', function (): void {
    $this->get('/')->assertOk()->assertSee(url('/guide-editeur'));
    $this->get('/documentation-api')->assertOk()->assertSee(url('/guide-editeur'));
});

it('translates the editor guide', function (): void {
    $this->from('/')->get('/locale/en')->assertRedirect('/');

    $this->get('/guide-editeur')->assertOk()
        ->assertSee('Prove your contribution')
        ->assertDontSee('Prouver votre contribution');
});

it('offers the interface language switch on the public pages', function (): void {
    // Endonyms, so a reader looking for English is not asked to know
    // the French word for it (D14).
    $this->get('/')->assertOk()
        ->assertSee('Français')
        ->assertSee('English');
});

it('keeps every language one link away with the menu closed', function (): void {
    $response = $this->get('/')->assertOk();

    // The menu is a details, so it ships closed. Each language is a
    // plain link inside it: crawlers and a reader without CSS reach
    // English without opening anything, and nothing here needs script.
    $response->assertSee('<details>', escape: false)
        ->assertDontSee('<details open', escape: false)
        ->assertSee(route('locale.switch', ['locale' => 'en']))
        ->assertSee(route('locale.switch', ['locale' => 'fr']));
});

it('names the current language on the button of the switch', function (): void {
    $this->get('/')->assertOk()
        // Closed, the menu states which language is in force rather
        // than leaving the reader to open it to find out.
        ->assertSeeInOrder(['<summary>', 'Français', '</summary>'], escape: false);

    $this->from('/')->get('/locale/en');

    $this->get('/')->assertOk()
        ->assertSeeInOrder(['<summary>', 'English', '</summary>'], escape: false);
});

it('applies the chosen interface locale and ignores an unoffered one', function (): void {
    $this->from('/')->get('/locale/en')->assertRedirect('/');
    $this->get('/')->assertOk()->assertSee('The feed');

    $this->from('/')->get('/locale/fr')->assertRedirect('/');
    $this->get('/')->assertOk()->assertSee('Le fil');

    // A locale the service does not offer never takes: the previous
    // choice stands rather than the app falling back silently.
    $this->from('/')->get('/locale/de')->assertRedirect('/');
    $this->get('/')->assertOk()->assertSee('Le fil');
});

it('states the content licence and what submitting commits the author to', function (): void {
    // Share-alike and the trademark undertaking are opposable only if
    // they are published: the rules page carries the numbered offence,
    // the legal page the licence itself (SPEC D15, 9.2 R6).
    $this->get('/mentions')->assertOk()
        ->assertSee('CC BY-SA 4.0')
        ->assertSee('droit des marques', escape: false);

    $this->get('/regles')->assertOk()
        ->assertSee('R6')
        ->assertSee('CC BY-SA 4.0');
});

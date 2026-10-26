<?php

declare(strict_types=1);

use App\Domain\Dolinews\Models\Project;
use App\Models\User;
use App\Support\Skins;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\View\FileViewFinder;
use Tests\Support\Factory;

/*
 * The skins the page sweep below runs under. A dataset is resolved before
 * the application boots, so it cannot read the configuration; the last test
 * of this file keeps the two aligned.
 */
const SKINS_UNDER_TEST = ['default', 'compact', 'airy'];

/**
 * Skins of the reader-facing pages (SPEC 6.6): the instance proposes one,
 * the visitor may pick another, and the back-office has none.
 *
 * Like the theme, the choice is a link that lands in the session, so it
 * works on pages that carry no JavaScript. The skin serving a page is named
 * by data-skin on <html>.
 */
it('serves the default skin until the visitor chooses', function (): void {
    $this->get('/fr')->assertOk()->assertSee('data-skin="default"', escape: false);
});

it('applies the chosen skin across the public pages, the guest screens and the account', function (): void {
    $this->from('/fr')->get('/skin/compact')->assertRedirect('/fr');

    foreach (['/fr', '/fr/regles', '/login'] as $uri) {
        $this->get($uri)->assertOk()->assertSee('data-skin="compact"', escape: false);
    }

    $reader = User::factory()->create();

    $this->actingAs($reader)->withSession(['skin' => 'compact'])
        ->get('/account')->assertOk()->assertSee('data-skin="compact"', escape: false);
});

it('ignores a skin the instance does not offer', function (): void {
    $this->from('/fr')->get('/skin/compact')->assertRedirect('/fr');
    $this->from('/fr')->get('/skin/sepia')->assertRedirect('/fr');

    // The previous choice stands rather than falling back silently.
    $this->get('/fr')->assertOk()->assertSee('data-skin="compact"', escape: false);
});

it('offers the switch on the public pages and the guest screens', function (string $uri): void {
    $this->get($uri)->assertOk()
        ->assertSee(route('skin.switch', ['skin' => 'default']))
        ->assertSee(route('skin.switch', ['skin' => 'compact']));
})->with(['/fr', '/login', '/fr/regles']);

it('serves the skin of the instance to a visitor who has not chosen', function (): void {
    config(['dolinews.skins.default' => 'compact']);

    $this->get('/fr')->assertOk()->assertSee('data-skin="compact"', escape: false);

    // And the visitor can still go back to the other one.
    $this->from('/fr')->get('/skin/default')->assertRedirect('/fr');
    $this->get('/fr')->assertOk()->assertSee('data-skin="default"', escape: false);
});

it('serves the skin of the instance to everyone when the choice is closed', function (): void {
    config(['dolinews.skins.default' => 'compact', 'dolinews.skins.visitor_choice' => false]);

    $this->withSession(['skin' => 'default'])->get('/fr')->assertOk()
        ->assertSee('data-skin="compact"', escape: false)
        ->assertDontSee(route('skin.switch', ['skin' => 'default']));

    $this->from('/fr')->get('/skin/default')->assertRedirect('/fr');
    $this->get('/fr')->assertOk()->assertSee('data-skin="compact"', escape: false);
});

it('falls back on the default skin when the configured one is not offered', function (): void {
    Log::spy();
    config(['dolinews.skins.default' => 'sepia']);

    $this->get('/fr')->assertOk()->assertSee('data-skin="default"', escape: false);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message): bool => str_contains($message, 'default skin is not offered'),
    );
});

it('drops an offered skin that has no stylesheet on disk', function (): void {
    Log::spy();
    config(['dolinews.skins.offered' => ['default', 'compact', 'ghost']]);

    expect(Skins::offered())->toBe(['default', 'compact']);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message): bool => str_contains($message, 'resources/skins/ghost/skin.css'),
    );
});

it('keeps the back-office out of every skin', function (): void {
    $moderator = User::factory()->moderator()->create();

    // The compact skin is applied first, in the same process: the
    // back-office request that follows must not inherit its view paths.
    $this->actingAs($moderator)->from('/fr')->get('/skin/compact')->assertRedirect('/fr');
    $this->actingAs($moderator)->get('/fr')->assertOk()->assertSee('data-skin="compact"', escape: false);

    $this->actingAs($moderator)->get('/admin')->assertOk()
        ->assertSee('data-theme="caprel"', escape: false)
        ->assertDontSee('data-skin=', escape: false)
        ->assertDontSee(route('skin.switch', ['skin' => 'default']));

    $finder = View::getFinder();

    expect($finder)->toBeInstanceOf(FileViewFinder::class);
    /** @var FileViewFinder $finder */
    expect($finder->getPaths())->toBe(array_values((array) config('view.paths')));
});

/**
 * Every page a reader is served renders under every skin. A skin replaces
 * views by name, so a default view that changes the variables it hands to
 * a partial can break a skin's copy of that partial without any test of the
 * default skin noticing.
 */
it('renders every reader-facing page under every skin', function (string $skin): void {
    [$owner, $editor] = Factory::contributorWithEditor();

    $project = Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'module-habille',
        'name' => 'Module habille',
        'summary' => 'Fiche du module habille',
    ]);

    $article = Factory::publishedArticle($owner, [
        'title' => 'Annonce du module habille',
        'version' => '3.1.0',
        'project_id' => $project->getKey(),
    ]);

    $public = [
        '/fr', '/fr?q=habille', '/fr/revue', '/fr/engagements', '/fr/regles', '/fr/donnees',
        '/fr/mentions', '/fr/guide-editeur', '/fr/documentation-api', '/fr/integrations',
        '/fr/preferences', '/fr/projets/module-habille', '/fr/editeurs/'.$editor->slug,
        route('articles.show', $article), '/fr/signaler/article/'.$article->getKey(),
        '/fr/signaler/projet/module-habille',
        '/login', '/register', '/forgot-password', '/reset-password/token-test',
    ];

    foreach ($public as $uri) {
        $this->withSession(['skin' => $skin])->get($uri)->assertOk()
            ->assertSee('data-skin="'.$skin.'"', escape: false);
    }

    $account = [
        '/account', '/account/articles', '/account/articles/new',
        '/account/articles/'.$article->getKey().'/edit', '/account/contribute',
        '/account/moderation', '/account/password', '/account/projects',
        '/account/projects/new', '/account/projects/'.$project->getKey().'/edit',
        '/account/tokens', '/account/translations', '/account/translations/automatique',
        '/account/translations/mandats',
    ];

    foreach ($account as $uri) {
        $this->actingAs($owner)->withSession(['skin' => $skin])->get($uri)->assertOk()
            ->assertSee('data-skin="'.$skin.'"', escape: false);
    }
})->with(SKINS_UNDER_TEST);

/**
 * The compact skin changes how the feed looks, never what it says: the
 * entry keeps every field of the default card, and the head of the feed
 * still names both delivery channels in words (SPEC 6.3, 6.4).
 */
it('states in the compact feed what the default feed states', function (): void {
    [$owner, $editor] = Factory::contributorWithEditor();

    $project = Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'module-dense',
        'name' => 'Module dense',
        'summary' => 'Fiche du module dense',
    ]);

    $article = Factory::publishedArticle($owner, [
        'title' => 'Correctif du module dense',
        'version' => '1.4.2',
        'focus' => 'security',
        'dolibarr_min' => 18,
        'dolibarr_max' => 22,
        'project_id' => $project->getKey(),
    ]);

    $this->withSession(['skin' => 'compact'])->get('/fr')->assertOk()
        ->assertSee('feed-row', escape: false)
        ->assertSee('Correctif du module dense')
        ->assertSee(route('articles.show', $article))
        ->assertSee('Module dense')
        ->assertSee($editor->name)
        ->assertSee('1.4.2')
        ->assertSee('Dolibarr 18 à 22')
        ->assertSee('Sécurité')
        ->assertSee($article->maturity->label())
        ->assertSee((string) $article->announcedAge())
        ->assertSee('<time class="feed-row-date" datetime="'.$article->published_at?->toDateString().'">', escape: false)
        ->assertSee('pour recevoir un courriel dès qu\'une nouvelle version est annoncée')
        ->assertSee('le flux RSS porte les mêmes annonces')
        // No card and no flag in this skin.
        ->assertDontSee('class="card overflow-hidden"', escape: false)
        ->assertDontSee('date-flag', escape: false);
});

/**
 * The airy skin drops the card and keeps the content: every field of the
 * default entry, the image put forward, the link on, and both delivery
 * channels named at the head of the feed (SPEC 6.3, 6.4).
 */
it('states in the airy feed what the default feed states', function (): void {
    [$owner, $editor] = Factory::contributorWithEditor();

    $project = Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'module-aere',
        'name' => 'Module aere',
        'summary' => 'Fiche du module aere',
    ]);

    $article = Factory::publishedArticle($owner, [
        'title' => 'Correctif du module aere',
        'version' => '2.0.1',
        'focus' => 'security',
        'dolibarr_min' => 19,
        'dolibarr_max' => 23,
        'project_id' => $project->getKey(),
    ]);

    $this->withSession(['skin' => 'airy'])->get('/fr')->assertOk()
        ->assertSee('class="feed-airy"', escape: false)
        ->assertSee('Correctif du module aere')
        ->assertSee(route('articles.show', $article))
        ->assertSee('Module aere')
        ->assertSee($editor->name)
        ->assertSee('2.0.1')
        ->assertSee('Dolibarr 19 à 23')
        ->assertSee('Sécurité')
        ->assertSee($article->maturity->label())
        ->assertSee((string) $article->announcedAge())
        ->assertSee('Lire la suite')
        ->assertSee('<time datetime="'.$article->published_at?->toDateString().'">', escape: false)
        ->assertSee('pour recevoir un courriel dès qu\'une nouvelle version est annoncée')
        ->assertSee('le flux RSS porte les mêmes annonces')
        ->assertDontSee('class="card overflow-hidden"', escape: false)
        ->assertDontSee('date-flag', escape: false);
});

/**
 * The airy skin moves the sections of the site to the footer, the review
 * page among them, and puts the presentation and the filters across the
 * page above the feed (SPEC 5.1, 6.4, 6.6).
 */
it('lays the airy feed out in one column with the navigation in the footer', function (): void {
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Annonce pleine largeur']);

    $html = (string) $this->withSession(['skin' => 'airy'])->get('/fr?q=pleine')->assertOk()->getContent();

    $header = substr($html, (int) strpos($html, '<header'), (int) strpos($html, '</header>') - (int) strpos($html, '<header'));
    $footer = substr($html, (int) strpos($html, '<footer'));

    // The search stays in the head of the page, the sections leave it.
    expect($header)->toContain('id="header-q"')
        ->and($header)->not->toContain(route('review.info'))
        ->and($header)->not->toContain(route('pages.editor-guide'));

    expect($footer)->toContain('aria-label="Navigation principale"')
        ->and($footer)->toContain(route('review.info'))
        ->and($footer)->toContain(route('pages.editor-guide'))
        ->and($footer)->toContain(config('dolinews.source_url'));

    // No side column: the presentation, then the filters, then the feed.
    $intro = strpos($html, 'pour recevoir un courriel');
    $filters = strpos($html, 'id="f-editor"');
    $feed = strpos($html, 'class="feed-airy"');

    expect($intro)->toBeInt()->and($filters)->toBeInt()->and($feed)->toBeInt()
        ->and($intro < $filters && $filters < $feed)->toBeTrue()
        ->and($html)->not->toContain('home-aside');

    // The filter form keeps what the default one keeps.
    expect($html)->toContain('<input type="hidden" name="q" value="pleine">')
        ->and($html)->not->toContain('id="refine-toggle" class="peer sr-only" checked');

    $this->withSession(['skin' => 'airy'])->get('/fr?project=dolinews')->assertOk()
        ->assertSee('id="refine-toggle" class="peer sr-only" checked', escape: false);
});

/**
 * A skin view replaces the default view of the same name. One whose
 * original was renamed or removed would sit there unused, and the skin
 * would silently fall back on the default for that piece.
 */
it('redefines only views that exist in the default skin', function (): void {
    $orphans = [];

    foreach (glob(resource_path('skins/*'), GLOB_ONLYDIR) ?: [] as $skinDirectory) {
        $views = $skinDirectory.'/views';

        if (! is_dir($views)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $relative = substr($file->getPathname(), strlen($views) + 1);

            if (! is_file(resource_path('views/'.$relative))) {
                $orphans[] = basename($skinDirectory).'/'.$relative;
            }
        }
    }

    expect($orphans)->toBe([]);
});

it('ships a stylesheet for every skin it offers', function (): void {
    foreach (array_diff(Skins::offered(), [Skins::DEFAULT]) as $skin) {
        expect(is_file(resource_path('skins/'.$skin.'/skin.css')))->toBeTrue();
    }

    // A new skin enters the page sweep above with its directory.
    expect(Skins::offered())->toBe(SKINS_UNDER_TEST);
});

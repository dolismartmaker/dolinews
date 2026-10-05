<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Enums\ReviewDecision;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Review\ReviewService;
use App\Models\User;
use Tests\Support\Factory;

/**
 * The WordPress integration of the toolbox (SPEC 6.4): a third-party
 * site renders a section of the feed itself, filtered by editor or by
 * project.
 *
 * The plugin is run for real, in a process of its own, against the
 * document /feeds.json actually answered: the rules the spec puts on a
 * card - stable alone by default, a maturity never without its age,
 * the Dolibarr range worded short, attribution and licence - have to
 * hold where the card is rendered, which is somebody else's site.
 */

/**
 * The plugin, fed the given feed document and shortcode attributes.
 *
 * @param  array<string, mixed>  $case
 * @return array{html: string, requests: list<string>}
 */
function runWordpressPlugin(array $case): array
{
    $path = tempnam(sys_get_temp_dir(), 'dolinews-wp-');
    expect($path)->toBeString();

    file_put_contents((string) $path, json_encode($case, JSON_THROW_ON_ERROR));

    $harness = base_path('tests/Support/wordpress-harness.php');
    $command = escapeshellcmd(PHP_BINARY).' '.escapeshellarg($harness).' '.escapeshellarg((string) $path).' 2>/dev/null';

    $output = shell_exec($command);

    unlink((string) $path);

    /** @var array{html: string, requests: list<string>}|null $decoded */
    $decoded = json_decode((string) $output, true);

    expect($decoded)->toBeArray();

    return (array) $decoded;
}

/**
 * A string as the block prints it: the plugin escapes every field it
 * renders, apostrophes included.
 */
function escapedForBlock(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * The feed document the service answers, as a string.
 *
 * @param  array<string, mixed>  $query
 */
function feedDocument(array $query = []): string
{
    $url = '/feeds.json'.($query === [] ? '' : '?'.http_build_query($query));

    return (string) test()->get($url)->getContent();
}

it('renders the feed of one editor on a third-party site', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();

    $project = Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'module-vitrine',
        'name' => 'Module Vitrine',
        'summary' => 'Resume',
    ]);

    $article = Factory::article($author, [
        'title' => 'Module Vitrine 3.2',
        'summary' => 'Correctif de securite sur la file d\'attente.',
        'version' => '3.2.0',
        'focus' => 'security',
        'dolibarr_min' => 18,
        'dolibarr_max' => 24,
    ]);
    $article->project_id = $project->getKey();
    $article->save();

    app(ArticleService::class)->submit($article->refresh(), $author);

    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        app(ReviewService::class)->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    // An announcement of another editor: the shortcode filters on a
    // slug, so it must not appear.
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Annonce etrangere au bloc']);

    $result = runWordpressPlugin([
        'status' => 200,
        'body' => feedDocument(['editor' => $editor->slug]),
        'atts' => ['editor' => $editor->slug, 'limit' => '5', 'title' => 'Nos annonces'],
    ]);

    $html = $result['html'];

    expect($html)->toContain('Module Vitrine 3.2')
        // Escaped on the way out, apostrophe included: what an editor
        // wrote is text on somebody else's page, never markup.
        ->and($html)->toContain('Correctif de securite sur la file d&#039;attente.')
        ->and($html)->not->toContain('Annonce etrangere au bloc')
        // The heading asked for, the editor named, the version shown.
        ->and($html)->toContain('Nos annonces')
        ->and($html)->toContain($editor->name)
        ->and($html)->toContain('3.2.0')
        // The Dolibarr range as the service words it, never "compatible".
        ->and($html)->toContain('Dolibarr 18 à 24')
        ->and($html)->not->toContain('compatible')
        // A security focus is the badge an integrator must not miss.
        ->and($html)->toContain('dolinews-feed__badge--security')
        // Attribution and licence travel with the copy (SPEC D15).
        ->and($html)->toContain('CC BY-SA 4.0')
        ->and($html)->toContain('creativecommons.org')
        // The link points at the announcement on the service.
        ->and($html)->toContain(route('articles.show', ['locale' => 'fr', 'article' => $article->getKey()]));

    // The address built from the attributes is the filtered feed.
    expect($result['requests'])->toHaveCount(1)
        ->and($result['requests'][0])->toContain('/feeds.json?editor='.$editor->slug);
});

it('never shows a maturity without how long ago it was announced', function (): void {
    $author = User::factory()->create();

    $beta = Factory::article($author, ['title' => 'Module Essai 0.9', 'maturity' => 'beta']);
    app(ArticleService::class)->submit($beta, $author);

    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        app(ReviewService::class)->postMessage($beta, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    $beta->refresh();
    $beta->published_at = now()->subMonths(8);
    $beta->save();

    $result = runWordpressPlugin([
        'status' => 200,
        // Named explicitly: a feed left alone lists stable alone
        // (SPEC 6.2), which is also the plugin's default.
        'body' => feedDocument(['maturity' => ['beta']]),
        'atts' => ['maturity' => 'beta'],
    ]);

    expect($result['html'])->toContain('Module Essai 0.9')
        ->and($result['html'])->toContain('beta - annoncée il y a 8 mois')
        ->and($result['requests'][0])->toContain('maturity%5B0%5D=beta');
});

it('lists stable announcements alone when no maturity is named', function (): void {
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Version stable du bloc']);
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Version beta du bloc', 'maturity' => 'beta']);

    $result = runWordpressPlugin([
        'status' => 200,
        'body' => feedDocument(),
        'atts' => [],
    ]);

    expect($result['html'])->toContain('Version stable du bloc')
        ->and($result['html'])->not->toContain('Version beta du bloc')
        ->and($result['requests'][0])->not->toContain('maturity');
});

it('words the badges in the language the shortcode asks for', function (): void {
    $author = User::factory()->create();

    $article = Factory::article($author, [
        'title' => 'Modulo Anuncio 2.0',
        'locale' => 'es_ES',
        'dolibarr_min' => 20,
    ]);

    app(ArticleService::class)->submit($article, $author);

    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        app(ReviewService::class)->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    $result = runWordpressPlugin([
        'status' => 200,
        'body' => feedDocument(['locale' => 'es_ES']),
        'atts' => ['locale' => 'es_ES'],
    ]);

    expect($result['html'])->toContain('Modulo Anuncio 2.0')
        ->and($result['html'])->toContain(__('Dolibarr :min et supérieur', ['min' => 20], 'es'));
});

it('says which language an announcement is in when it is not the reader\'s', function (): void {
    $author = User::factory()->create();

    $article = Factory::article($author, ['title' => 'English only announcement', 'locale' => 'en_US']);
    app(ArticleService::class)->submit($article, $author);

    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        app(ReviewService::class)->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    $result = runWordpressPlugin([
        'status' => 200,
        'body' => feedDocument(['locale' => 'fr_FR']),
        'atts' => ['locale' => 'fr_FR'],
    ]);

    // Shown rather than hidden (SPEC 6.1), and the reader is told
    // before the click which language awaits them.
    expect($result['html'])->toContain('English only announcement')
        ->and($result['html'])->toContain('en English');
});

it('serves the last known answer when the service cannot be reached', function (): void {
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Annonce mise en cache']);

    $document = feedDocument();

    // First call fills the cache, second one is answered from it
    // without touching the network.
    $cached = runWordpressPlugin([
        'status' => 200,
        'body' => $document,
        'atts' => [],
        'twice' => true,
    ]);

    expect($cached['html'])->toContain('Annonce mise en cache')
        ->and($cached['requests'])->toHaveCount(1)
        // The stylesheet goes out once per page, not once per block.
        ->and(substr_count($cached['html'], '<style>'))->toBe(0);

    // A network failure on a cold cache prints nothing at all: a page
    // of a third-party site is not where our outage is displayed.
    $failed = runWordpressPlugin([
        'error' => 'cURL error 28: connection timed out',
        'atts' => [],
    ]);

    expect($failed['html'])->toBe('');
});

it('escapes what an editor wrote before it reaches a third-party page', function (): void {
    $author = User::factory()->create();

    $article = Factory::article($author, [
        'title' => 'Module <script>alert(1)</script> 1.0',
        'summary' => 'Resume avec "guillemets" & une balise <b>gras</b>.',
    ]);

    app(ArticleService::class)->submit($article, $author);

    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        app(ReviewService::class)->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    $result = runWordpressPlugin([
        'status' => 200,
        'body' => feedDocument(),
        'atts' => [],
    ]);

    expect($result['html'])->not->toContain('<script>')
        ->and($result['html'])->toContain('&lt;script&gt;')
        ->and($result['html'])->toContain('&lt;b&gt;gras&lt;/b&gt;');
});

it('keeps the plugin free of any mention of a module being compatible', function (): void {
    $source = (string) file_get_contents(base_path('integrations/wordpress/dolinews-feed.php'));

    // SPEC D1: the service says what was announced, and when. An
    // integration that writes "compatible with v22" would say the
    // present state of a module, which it does not know.
    expect($source)->not->toContain('compatible with')
        ->and($source)->not->toContain('supports Dolibarr')
        // No account, no token: reading is free and accountless.
        ->and($source)->not->toContain('Authorization')
        ->and($source)->not->toContain('api/v1');
});

it('publishes the article identifier and slugs a client can filter on', function (): void {
    [$author, $editor] = Factory::contributorWithEditor();

    $project = Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'module-identifie',
        'name' => 'Module Identifie',
        'summary' => 'Resume',
    ]);

    $article = Factory::article($author, ['title' => 'Module Identifie 1.4', 'version' => '1.4.0']);
    $article->project_id = $project->getKey();
    $article->save();

    app(ArticleService::class)->submit($article->refresh(), $author);

    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        app(ReviewService::class)->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    $payload = $this->get('/feeds.json')->json();
    $extra = $payload['items'][0]['_dolinews'];

    /** @var Article $published */
    $published = $article->refresh();

    expect($extra['id'])->toBe($published->getKey())
        ->and($extra['editor']['slug'])->toBe($editor->slug)
        ->and($extra['project']['slug'])->toBe('module-identifie')
        ->and($extra['version'])->toBe('1.4.0')
        ->and($extra['locale'])->toBe('fr_FR')
        ->and($extra['maturity'])->toBe('stable');
});

it('takes the wording of the block from the feed, in the language asked for', function (): void {
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Annonce avec libelles']);

    $french = runWordpressPlugin([
        'status' => 200,
        'body' => feedDocument(['locale' => 'fr_FR']),
        'atts' => ['locale' => 'fr_FR'],
    ]);

    $spanish = runWordpressPlugin([
        'status' => 200,
        'body' => feedDocument(['locale' => 'es_ES']),
        'atts' => ['locale' => 'es_ES'],
    ]);

    // A plugin shipped with English defaults renders half a page in
    // English on a site that is not: the two words it needs come from
    // the feed.
    // Escaped on the way out, like every other string of the block.
    expect($french['html'])->toContain(escapedForBlock(__('Lire l\'annonce')))
        ->and($french['html'])->not->toContain('Read the announcement')
        ->and($spanish['html'])->toContain(escapedForBlock(__('Lire l\'annonce', [], 'es')));

    // Set explicitly, the attribute still wins.
    $chosen = runWordpressPlugin([
        'status' => 200,
        'body' => feedDocument(['locale' => 'fr_FR']),
        'atts' => ['locale' => 'fr_FR', 'link_text' => 'Voir chez nous'],
    ]);

    expect($chosen['html'])->toContain('Voir chez nous');
});

it('says in the language of the feed that nothing matches the filter', function (): void {
    $empty = runWordpressPlugin([
        'status' => 200,
        'body' => feedDocument(['editor' => 'editeur-qui-nexiste-pas']),
        'atts' => ['editor' => 'editeur-qui-nexiste-pas'],
    ]);

    expect($empty['html'])->toContain(escapedForBlock(__('Aucune annonce publiée pour l\'instant.')));
});

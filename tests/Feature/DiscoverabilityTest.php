<?php

declare(strict_types=1);

use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Models\Project;
use App\Models\User;
use Tests\Support\Factory;

/**
 * What the service says about itself to whoever is not reading it.
 *
 * A published announcement that no engine finds and that shares as a
 * bare URL reaches the people who already knew where to look, which is
 * the population the service exists to widen (SPEC 1).
 */

/**
 * Extract the value of an attribute from the one tag matching a needle.
 */
function metaValue(string $html, string $needle, string $attribute = 'content'): ?string
{
    $matches = [];
    $pattern = '/<[^>]*'.preg_quote($needle, '/').'[^>]*'.preg_quote($attribute, '/').'="([^"]*)"[^>]*>/';

    return preg_match($pattern, $html, $matches) === 1 ? $matches[1] : null;
}

/**
 * The decoded schema.org block of a page.
 *
 * @return array<string, mixed>
 */
function structuredDataOf(string $html): array
{
    $matches = [];
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($matches[1] ?? '{}', true) ?: [];

    return $decoded;
}

it('tells crawlers what to read and where the map is', function (): void {
    $response = $this->get('/robots.txt');

    $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    $body = $response->getContent();

    expect($body)->toContain('Sitemap: '.route('sitemap.index'))
        ->and($body)->toContain('Allow: /')
        // Tokenised addresses are credentials, not content (SPEC 6.4).
        ->and($body)->toContain('Disallow: /feeds/')
        ->and($body)->toContain('Disallow: /desabonnement/')
        // The generic feeds stay reachable: the refusal above targets
        // the personal ones, which sit one path segment deeper.
        ->and($this->get('/feeds.xml')->getStatusCode())->toBe(200);
});

it('maps the published service in an index of sections', function (): void {
    $response = $this->get('/sitemap.xml');

    $response->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $body = (string) $response->getContent();

    expect($body)->toContain('<sitemapindex')
        ->and($body)->toContain(route('sitemap.section', ['section' => 'pages']))
        ->and($body)->toContain(route('sitemap.section', ['section' => 'projects']))
        ->and($body)->toContain(route('sitemap.section', ['section' => 'editors']))
        // Always one article section, even on an empty service.
        ->and($body)->toContain(route('sitemap.section', ['section' => 'articles-1']));

    expect(simplexml_load_string($body))->not->toBeFalse();
});

it('lists the static pages and every published announcement', function (): void {
    $published = Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Sortie listee dans le plan',
    ]);
    $draft = Factory::article(User::factory()->create(), ['title' => 'Brouillon jamais publie']);

    $pages = (string) $this->get('/sitemap-pages.xml')->assertOk()->getContent();

    expect($pages)->toContain(route('home'))
        ->toContain(route('pages.rules'))
        ->toContain(route('pages.commitments'))
        // One report form per article holds no content of its own
        // (SPEC 9.9), and the authentication screens are not content.
        ->and($pages)->not->toContain('/fr/signaler/')
        ->and($pages)->not->toContain('/login');

    $articles = (string) $this->get('/sitemap-articles-1.xml')->assertOk()->getContent();

    expect($articles)->toContain(route('articles.show', ['article' => $published->getKey()]))
        ->and($articles)->not->toContain(route('articles.show', ['article' => $draft->getKey()]))
        ->and(simplexml_load_string($articles))->not->toBeFalse();
});

it('maps the sheets and the editors', function (): void {
    $author = User::factory()->create();
    $editor = (new EditorService)->create($author, [
        'name' => 'ACME modules',
        'contact_email' => 'acme@example.test',
    ]);
    Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'module-xy',
        'name' => 'Module XY',
        'summary' => 'Gestion des XY pour Dolibarr',
    ]);

    expect((string) $this->get('/sitemap-projects.xml')->assertOk()->getContent())
        ->toContain(route('projects.show', ['slug' => 'module-xy']));

    expect((string) $this->get('/sitemap-editors.xml')->assertOk()->getContent())
        ->toContain(route('editors.show', ['slug' => 'acme-modules']));
});

it('refuses a section nobody serves', function (): void {
    $this->get('/sitemap-wordpress.xml')->assertNotFound();
});

it('carries a sharing card and its icons on the feed', function (): void {
    $html = (string) $this->get('/fr')->assertOk()->getContent();

    expect(metaValue($html, 'property="og:site_name"'))->toBe('DoliNews')
        ->and(metaValue($html, 'property="og:type"'))->toBe('website')
        ->and(metaValue($html, 'property="og:url"'))->toBe(route('home'))
        ->and(metaValue($html, 'name="twitter:card"'))->toBe('summary_large_image')
        // Absolute, always: a preview renderer never resolves a relative
        // address against the page it read.
        ->and(metaValue($html, 'property="og:image"'))->toStartWith('http')
        ->and($html)->toContain('rel="icon"')
        ->and($html)->toContain('apple-touch-icon');
});

it('points every filtered view of the feed at the bare feed', function (): void {
    Factory::publishedArticle(User::factory()->create());

    $filtered = (string) $this->get('/fr?focus=security&dolibarr=22')->assertOk()->getContent();

    expect(metaValue($filtered, 'rel="canonical"', 'href'))->toBe(route('home'));

    // Page two is not a variant of page one: it carries other
    // announcements and declares itself.
    $second = (string) $this->get('/fr?page=2')->assertOk()->getContent();

    expect(metaValue($second, 'rel="canonical"', 'href'))->toBe(route('home').'?page=2');
});

it('declares the language versions of one announcement to each other', function (): void {
    $author = User::factory()->create();
    $source = Factory::publishedArticle($author, ['title' => 'Sortie 3.0']);
    $translation = Factory::publishedTranslation($author, $source, 'es_ES');

    $sourceUrl = route('articles.show', ['locale' => 'fr', 'article' => $source->getKey()]);
    $translationUrl = route('articles.show', ['locale' => 'es', 'article' => $translation->getKey()]);

    $html = (string) $this->get($translationUrl)->assertOk()->getContent();

    expect($html)->toContain('hreflang="es-ES" href="'.$translationUrl.'"')
        ->and($html)->toContain('hreflang="fr-FR" href="'.$sourceUrl.'"')
        // The language nobody asked for falls back on the source
        // version, never on a translation (SPEC 5.1).
        ->and($html)->toContain('hreflang="x-default" href="'.$sourceUrl.'"');
});

it('describes an announcement as an announcement', function (): void {
    $author = User::factory()->create();
    $editor = Factory::editorFor($author);
    $project = Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'module-xy',
        'name' => 'Module XY',
        'summary' => 'Gestion des XY',
    ]);
    $article = Factory::publishedArticle($author, [
        'title' => 'Module XY 3.0',
        'version' => '3.0',
        'project_id' => $project->getKey(),
    ]);

    $html = (string) $this->get(route('articles.show', $article))->assertOk()->getContent();
    $data = structuredDataOf($html);

    expect($data['@type'] ?? null)->toBe('NewsArticle')
        ->and($data['headline'] ?? null)->toBe('Module XY 3.0')
        ->and($data['inLanguage'] ?? null)->toBe('fr-FR')
        ->and($data['datePublished'] ?? null)->not->toBeNull()
        // Share-alike travels with every copy (SPEC D15).
        ->and($data['license'] ?? null)->toBe(config('dolinews.content_license.url'))
        ->and($data['author']['name'] ?? null)->toBe($editor->name)
        ->and($data['about']['softwareVersion'] ?? null)->toBe('3.0');

    // The sharing card of an article says it is one, and in its own
    // language rather than the reader's.
    expect(metaValue($html, 'property="og:type"'))->toBe('article')
        ->and(metaValue($html, 'property="og:locale"'))->toBe('fr_FR');
});

it('never states the current state of a module in its structured data', function (): void {
    $author = User::factory()->create();
    $editor = Factory::editorFor($author);
    $project = Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'module-xy',
        'name' => 'Module XY',
        'summary' => 'Gestion des XY',
        'license' => 'GPL-3.0',
    ]);

    $data = structuredDataOf((string) $this->get('/fr/projets/module-xy')->assertOk()->getContent());

    expect($data['@type'] ?? null)->toBe('SoftwareApplication')
        ->and($data['name'] ?? null)->toBe('Module XY')
        ->and($data['license'] ?? null)->toBe('GPL-3.0')
        ->and($data['author']['name'] ?? null)->toBe($editor->name)
        // The sheet carries no Dolibarr compatibility (SPEC D1), so
        // nothing dated leaks into a description that never ages.
        ->and($data)->not->toHaveKey('softwareVersion');

    $editorData = structuredDataOf((string) $this->get('/fr/editeurs/'.$editor->slug)->assertOk()->getContent());

    expect($editorData['@type'] ?? null)->toBe('Organization')
        ->and($editorData['name'] ?? null)->toBe($editor->name);
});

it('offers the free search as the way into the feed', function (): void {
    $data = structuredDataOf((string) $this->get('/fr')->assertOk()->getContent());

    expect($data['@type'] ?? null)->toBe('WebSite')
        ->and($data['potentialAction']['target']['urlTemplate'] ?? null)
        ->toBe(route('home').'?q={search_term_string}');
});

it('escapes what third parties wrote into the head', function (): void {
    $article = Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Module "XY" <script>alert(1)</script>',
        'summary' => 'Un résumé avec "guillemets" & esperluette.',
    ]);

    $html = (string) $this->get(route('articles.show', $article))->assertOk()->getContent();

    // Neither the title nor the summary may close an attribute, a tag or
    // the data block: announcements are written by third parties
    // (SPEC 5.1), and review is not an escaping mechanism.
    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and(metaValue($html, 'property="og:title"'))->toContain('&quot;XY&quot;');

    $data = structuredDataOf($html);

    expect($data['headline'] ?? null)->toBe('Module "XY" <script>alert(1)</script>');
});

it('keeps the authentication screens out of the index', function (): void {
    expect(metaValue((string) $this->get('/login')->getContent(), 'name="robots"'))
        ->toBe('noindex');

    $article = Factory::publishedArticle(User::factory()->create());

    expect(metaValue((string) $this->get(route('reports.article', $article))->getContent(), 'name="robots"'))
        ->toBe('noindex');

    // The feed itself carries no such refusal.
    expect(metaValue((string) $this->get('/fr')->getContent(), 'name="robots"'))
        ->toBeNull();
});

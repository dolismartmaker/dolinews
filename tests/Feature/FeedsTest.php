<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Articles\TranslationService;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Enums\ReviewDecision;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Review\ReviewService;
use App\Domain\Dolinews\Subscriptions\WatchService;
use App\Models\User;
use Tests\Support\Factory;

/**
 * Feeds (SPEC 6.4): generic RSS without an account and behind a
 * bounded cache, JSON flavour, personal tokenized feeds carrying the
 * watch filters.
 */
it('serves a valid rss document with the stable default filter', function (): void {
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Release stable en flux']);
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Release beta en flux', 'maturity' => 'beta']);

    $response = $this->get('/feeds.xml');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');

    $xml = simplexml_load_string($response->getContent());

    expect($xml)->not->toBeFalse()
        ->and((string) $xml->channel->title)->toContain('DoliNews')
        ->and($response->getContent())->toContain('Release stable en flux')
        ->and($response->getContent())->not->toContain('Release beta en flux');
});

it('applies feed filters like the web surface', function (): void {
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Correctif securite 22.x', 'focus' => 'security']);

    $kept = $this->get('/feeds.xml?focus=security');
    $kept->assertOk();

    $refused = $this->get('/feeds.xml?focus=doc');

    expect($kept->getContent())->toContain('Correctif securite 22.x')
        ->and($refused->getContent())->not->toContain('Correctif securite 22.x');
});

it('serves the json feed flavour', function (): void {
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Release json']);

    $response = $this->get('/feeds.json');

    $response->assertOk();

    $payload = $response->json();

    expect($payload['version'])->toContain('jsonfeed.org')
        ->and($payload['items'])->not->toBeEmpty()
        ->and($payload['items'][0]['title'])->toBe('Release json');
});

it('carries the content licence in both feed flavours', function (): void {
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Release licenciee']);

    $rss = $this->get('/feeds.xml');
    $json = $this->get('/feeds.json');

    $xml = simplexml_load_string($rss->getContent());

    expect((string) $xml->channel->copyright)->toContain('CC BY-SA 4.0')
        ->and((string) $xml->channel->copyright)->toContain('creativecommons.org')
        ->and($json->json('_license.name'))->toBe('CC BY-SA 4.0')
        ->and($json->json('_license.url'))->toContain('creativecommons.org');
});

it('serves a personal token feed restricted to the watches', function (): void {
    $user = User::factory()->create();
    $token = app(WatchService::class)->issueFeedToken($user);

    $author = User::factory()->create();
    $editor = (new EditorService)->create($author, [
        'name' => 'Editeur suivi',
        'contact_email' => 'followed@editeur.test',
    ]);

    $project = Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'module-suivi',
        'name' => 'Module suivi',
        'summary' => 'Resume',
    ]);

    app(WatchService::class)->toggleProject($user, $project, ['focus' => ['security']]);

    $followed = Factory::article($author);
    $followed->project_id = $project->getKey();
    $followed->save();

    app(ArticleService::class)->submit($followed->refresh(), $author);

    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        app(ReviewService::class)->postMessage(
            $followed,
            $moderator,
            'accord',
            ReviewDecision::ACCEPTED,
        );
    }

    // An unrelated published announcement: never in the personal feed.
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Annonce non suivie']);

    $response = $this->get('/feeds/'.$token);

    $response->assertOk();

    expect($response->getContent())->toContain('Module XY 2.1')
        ->and($response->getContent())->not->toContain('Annonce non suivie');
});

it('refuses an unknown personal feed token', function (): void {
    $this->get('/feeds/'.str_repeat('a', 32))->assertNotFound();
});

it('regenerating the token revokes the old url', function (): void {
    $user = User::factory()->create();
    $old = app(WatchService::class)->issueFeedToken($user);
    $new = app(WatchService::class)->regenerateFeedToken($user);

    expect($new)->not->toBe($old);

    $this->get('/feeds/'.$old)->assertNotFound();
    $this->get('/feeds/'.$new)->assertOk();
});

it('serves one language version per announcement in both flavours', function (): void {
    // A bilingual announcement used to appear twice in the same feed,
    // once per language. The requested language wins, and what nobody
    // translated is still served (SPEC 6.1).
    $author = User::factory()->create();

    $source = Factory::publishedArticle($author, [
        'title' => 'Sortie en francais dans le flux',
        'locale' => 'fr_FR',
    ]);

    $translation = app(TranslationService::class)->submitTranslation($source, $author, 'en_US', [
        'title' => 'English release in the feed',
        'summary' => 'English summary for the feed.',
        'body' => '## English body',
    ]);

    app(ArticleService::class)->submit($translation, $author);

    $review = app(ReviewService::class);

    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        $review->postMessage($translation, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    expect($translation->refresh()->status->value)->toBe('published');

    Factory::publishedArticle($author, [
        'title' => 'Entree jamais traduite',
        'locale' => 'en_US',
    ]);

    $rss = $this->get('/feeds.xml?locale=fr');

    $rss->assertOk()
        ->assertSee('Sortie en francais dans le flux')
        ->assertDontSee('English release in the feed')
        ->assertSee('Entree jamais traduite');

    $json = $this->get('/feeds.json?locale=fr');

    $json->assertOk()
        ->assertSee('Sortie en francais dans le flux')
        ->assertDontSee('English release in the feed')
        ->assertSee('Entree jamais traduite');
});

it('caches the rss feed per language', function (): void {
    // The cache key is built from the filters. With an unresolved
    // locale, the first visitor's language was served to everyone for
    // the whole TTL.
    $author = User::factory()->create();

    $source = Factory::publishedArticle($author, [
        'title' => 'Version francaise en cache',
        'locale' => 'fr_FR',
    ]);

    $translation = app(TranslationService::class)->submitTranslation($source, $author, 'en_US', [
        'title' => 'English version in cache',
        'summary' => 'English summary.',
        'body' => '## Body',
    ]);

    app(ArticleService::class)->submit($translation, $author);

    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        app(ReviewService::class)->postMessage($translation, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    // No explicit locale parameter: the interface language decides, and
    // each one gets its own cache entry. The language is negotiated
    // from the request like a browser's, not set on the container: the
    // middleware resolves it per request and would overwrite that.
    $this->withHeaders(['Accept-Language' => 'fr'])
        ->get('/feeds.xml')
        ->assertSee('Version francaise en cache')
        ->assertDontSee('English version in cache');

    $this->withHeaders(['Accept-Language' => 'en'])
        ->get('/feeds.xml')
        ->assertSee('English version in cache')
        ->assertDontSee('Version francaise en cache');
});

it('never writes the raw request into the cached feed document', function (): void {
    Factory::publishedArticle(User::factory()->create());

    // First visitor, carrying an unknown parameter and a forged Host.
    $this->withHeader('Host', 'attaquant.test')
        ->get('/feeds.xml?focus=security&utm_source=%22%3E%3Cinjection');

    $document = $this->get('/feeds.xml?focus=security')->getContent();

    expect($document)->not->toContain('attaquant.test')
        ->not->toContain('utm_source')
        ->and($document)->toContain(route('feeds.rss').'?focus=security');
});

it('builds the json feed url from the route too', function (): void {
    Factory::publishedArticle(User::factory()->create());

    $response = $this->withHeader('Host', 'attaquant.test')
        ->getJson('/feeds.json?focus=security&utm_source=x');

    expect($response->json('feed_url'))
        ->toBe(route('feeds.json').'?focus=security');
});

it('names the channel without a trailing space when nothing is filtered', function (): void {
    // Concatenated blindly, the unfiltered feed came out as "DoliNews "
    // with the space of its missing suffix, which readers show as typed.
    $feed = $this->get('/feeds.xml?locale=fr');

    $feed->assertOk();

    expect($feed->getContent())->toContain('<title>DoliNews</title>')
        ->and($feed->getContent())->not->toContain('<title>DoliNews </title>');
});

it('declares the language and the date of its newest entry', function (): void {
    $article = Factory::publishedArticle(User::factory()->create());

    $feed = $this->get('/feeds.xml?locale=fr');

    $feed->assertOk();

    expect($feed->getContent())->toContain('<language>fr</language>')
        ->and($feed->getContent())->toContain(
            '<lastBuildDate>'.$article->published_at?->toRfc2822String().'</lastBuildDate>',
        );
});

it('links the feed back to the feed page without repeating its language', function (): void {
    $feed = $this->get('/feeds.xml?locale=fr');

    $feed->assertOk();

    // The language is a segment of the address, never a parameter
    // (SPEC 6.5).
    expect($feed->getContent())->not->toContain('/fr?locale=fr');
});

it('filters both flavours by editor slug', function (): void {
    // The filter a third-party site integrating the feed relies on
    // first (SPEC 6.4), and the one no test covered.
    [$author, $editor] = Factory::contributorWithEditor();

    $wanted = Factory::article($author, ['title' => 'Annonce de l editeur suivi']);
    app(ArticleService::class)->submit($wanted, $author);

    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        app(ReviewService::class)->postMessage($wanted, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    Factory::publishedArticle(User::factory()->create(), ['title' => 'Annonce d un autre editeur']);

    $rss = $this->get('/feeds.xml?editor='.$editor->slug);
    $json = $this->get('/feeds.json?editor='.$editor->slug);

    $rss->assertOk();
    $json->assertOk();

    expect($rss->getContent())->toContain('Annonce de l editeur suivi')
        ->and($rss->getContent())->not->toContain('Annonce d un autre editeur')
        ->and(collect($json->json('items'))->pluck('title')->all())
        ->toBe(['Annonce de l editeur suivi']);
});

it('carries what a third-party client needs to render the card itself', function (): void {
    // The _dolinews extension of each JSON item (SPEC 6.4): raw values
    // AND the wording, so that an integration says what the feed says -
    // a Dolibarr range written short, a maturity never without its age.
    $author = User::factory()->create();

    $article = Factory::article($author, [
        'title' => 'Module Extension 2.0',
        'version' => '2.0.1',
        'maturity' => 'beta',
        'focus' => 'security',
        'dolibarr_min' => 20,
        'dolibarr_max' => 24,
    ]);

    app(ArticleService::class)->submit($article, $author);

    foreach (User::factory()->count(3)->moderator()->create() as $moderator) {
        app(ReviewService::class)->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    $item = $this->get('/feeds.json?locale=fr&maturity[]=beta')->json('items.0._dolinews');

    expect($item['version'])->toBe('2.0.1')
        ->and($item['maturity'])->toBe('beta')
        ->and($item['focus'])->toBe('security')
        ->and($item['dolibarr_min'])->toBe(20)
        ->and($item['dolibarr_max'])->toBe(24)
        ->and($item['editor']['slug'])->toBe(Factory::editorFor($author)->slug)
        ->and($item['labels']['dolibarr'])->toBe('Dolibarr 20 à 24')
        ->and($item['labels']['maturity'])->toBe('beta')
        ->and($item['labels']['announced_age'])->toBe('annoncée aujourd\'hui')
        ->and($item['labels']['focus'])->toBe('Sécurité')
        // The announcement is in the language asked for: nothing to warn
        // the reader about.
        ->and($item['labels']['language'])->toBeNull();
});

it('words the extension in the language the feed was asked in', function (): void {
    $author = User::factory()->create();

    Factory::publishedArticle($author, [
        'title' => 'Module Langue 1.0',
        'dolibarr_min' => 20,
    ]);

    // A third-party site has no session here: the locale parameter is
    // the only thing that can decide the wording (SPEC 6.5).
    $spanish = $this->get('/feeds.json?locale=es_ES')->json('items.0._dolinews.labels');
    $french = $this->get('/feeds.json?locale=fr_FR')->json('items.0._dolinews.labels');

    expect($french['dolibarr'])->toBe('Dolibarr 20 et supérieur')
        ->and($spanish['dolibarr'])->toBe(__('Dolibarr :min et supérieur', ['min' => 20], 'es'))
        ->and($spanish['dolibarr'])->not->toBe($french['dolibarr'])
        // A French announcement read from a Spanish block says so.
        ->and($spanish['language'])->toBe(__('en', [], 'es').' Français');
});

it('never announces a Dolibarr floor the module builder wrote by itself', function (): void {
    // The generator writes need_dolibarr_version = array(11, -3) into
    // every descriptor: read as announced, it states a version nobody
    // chose (SPEC 6.1). The extension drops it like the views do.
    $default = (int) config('dolinews.dolibarr_generator_default_min');

    Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Module Plancher Par Defaut 1.0',
        'dolibarr_min' => $default,
        'dolibarr_max' => 24,
    ]);

    $item = $this->get('/feeds.json?locale=fr')->json('items.0._dolinews');

    expect($item['dolibarr_min'])->toBeNull()
        ->and($item['dolibarr_max'])->toBe(24)
        ->and($item['labels']['dolibarr'])->toBe('Dolibarr jusqu\'à 24');
});

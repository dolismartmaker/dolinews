<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
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

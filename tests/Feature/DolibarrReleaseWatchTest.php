<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\Focus;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\ServiceState;
use App\Domain\Dolinews\Projects\ProjectService;
use App\Domain\Dolinews\Releases\ProxyReleaseWriter;
use App\Domain\Dolinews\Releases\ReleaseWatchService;
use App\Domain\Dolinews\Seo\ArticleUrl;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\Factory;

/**
 * Watch of the Dolibarr core releases (SPEC 5.8).
 *
 * What the suite pins down, in order of what it would cost to lose:
 * the watch submits and never publishes (D6), it reproduces nothing of
 * the release note (D15), it never announces the same version twice,
 * and a quota refusal costs nothing.
 */
function watchedProject(): Project
{
    $author = User::factory()->create(['email' => 'veille@dolinews.test']);
    $editor = Factory::editorFor($author);

    $project = app(ProjectService::class)->create($editor, [
        'name' => 'Dolibarr',
        'summary' => 'Le progiciel de gestion.',
    ]);

    config([
        'dolinews.releases.project' => $project->slug,
        'dolinews.releases.author_email' => $author->email,
        'dolinews.releases.writer.endpoint' => '',
        'dolinews.releases.writer.token' => '',
    ]);

    return $project;
}

/**
 * An Atom feed holding the given entries.
 *
 * @param  array<int, array{tag: string, updated: string, content?: string}>  $entries
 */
function releaseFeed(array $entries): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom">';

    foreach ($entries as $entry) {
        $url = 'https://forge.test/Dolibarr/dolibarr/releases/tag/'.$entry['tag'];

        $xml .= '<entry>'
            .'<id>tag:forge.test,2008:Repository/1/'.$entry['tag'].'</id>'
            .'<updated>'.$entry['updated'].'</updated>'
            .'<link rel="alternate" type="text/html" href="'.$url.'"/>'
            .'<title>'.$entry['tag'].'</title>'
            .'<content type="html">'.htmlspecialchars($entry['content'] ?? '<ul><li>FIX: petite correction</li></ul>').'</content>'
            .'</entry>';
    }

    return $xml.'</feed>';
}

/**
 * @param  array<int, array{tag: string, updated: string, content?: string}>  $entries
 */
function fakeFeed(array $entries): void
{
    Http::fake([
        '*' => Http::response(releaseFeed($entries), 200, ['Content-Type' => 'application/atom+xml']),
    ]);
}

it('records its starting point and submits nothing on the first run', function (): void {
    watchedProject();
    fakeFeed([['tag' => '21.0.1', 'updated' => now()->subDay()->toIso8601String()]]);

    $this->artisan('dolinews:watch-dolibarr-releases')->assertSuccessful();

    // Without this boundary, the first run turns the whole history the
    // feed carries into pending articles.
    expect(ServiceState::read(ReleaseWatchService::STARTED_KEY))->not->toBeNull()
        ->and(Article::query()->count())->toBe(0);
});

it('submits a new stable release to the review, and never publishes it', function (): void {
    watchedProject();
    fakeFeed([['tag' => '21.0.1', 'updated' => now()->subHour()->toIso8601String()]]);

    $this->artisan('dolinews:watch-dolibarr-releases', ['--since' => now()->subWeek()->toDateString()])
        ->assertSuccessful();

    $article = Article::query()->firstOrFail();

    // The heart of the thing (D6): a token submits, a human publishes.
    expect($article->status)->toBe(ArticleStatus::PENDING)
        ->and($article->published_at)->toBeNull()
        ->and($article->version)->toBe('21.0.1')
        ->and($article->dolibarr_min)->toBe(21)
        ->and($article->dolibarr_max)->toBe(21)
        ->and($article->auto_drafted)->toBeTrue();
});

it('leaves pre-releases and unreadable tags out', function (): void {
    watchedProject();
    fakeFeed([
        ['tag' => '21.0.0-beta', 'updated' => now()->subHour()->toIso8601String()],
        ['tag' => '20.0.0-rc1', 'updated' => now()->subHour()->toIso8601String()],
        ['tag' => 'nightly', 'updated' => now()->subHour()->toIso8601String()],
        ['tag' => '20.0.4', 'updated' => now()->subHour()->toIso8601String()],
    ]);

    $this->artisan('dolinews:watch-dolibarr-releases', ['--since' => now()->subWeek()->toDateString()])
        ->assertSuccessful();

    expect(Article::query()->pluck('version')->all())->toBe(['20.0.4']);
});

it('announces every maintained branch, not just the newest', function (): void {
    watchedProject();
    fakeFeed([
        ['tag' => '21.0.1', 'updated' => now()->subHour()->toIso8601String()],
        ['tag' => '20.0.4', 'updated' => now()->subHours(2)->toIso8601String()],
        ['tag' => '19.0.6', 'updated' => now()->subHours(3)->toIso8601String()],
    ]);

    // The security fix an integrator has to apply is usually the one
    // back-ported to the branch they actually run.
    $this->artisan('dolinews:watch-dolibarr-releases', ['--since' => now()->subWeek()->toDateString()])
        ->assertSuccessful();

    expect(Article::query()->pluck('version')->sort()->values()->all())
        ->toBe(['19.0.6', '20.0.4', '21.0.1']);
});

it('never announces the same version twice, whatever became of the first article', function (): void {
    $project = watchedProject();
    $author = User::query()->where('email', 'veille@dolinews.test')->firstOrFail();

    // A rejected article is a decision the team took about this release.
    $rejected = Factory::article($author, [
        'project_id' => $project->getKey(),
        'title' => 'Dolibarr 21.0.1',
        'version' => '21.0.1',
    ]);
    $rejected->status = ArticleStatus::REJECTED;
    $rejected->save();

    fakeFeed([['tag' => '21.0.1', 'updated' => now()->subHour()->toIso8601String()]]);

    $this->artisan('dolinews:watch-dolibarr-releases', ['--since' => now()->subWeek()->toDateString()])
        ->assertSuccessful();

    expect(Article::query()->where('version', '21.0.1')->count())->toBe(1);
});

it('reads the security focus off the changelog, and puts it at the head of the queue', function (): void {
    watchedProject();
    fakeFeed([[
        'tag' => '20.0.4',
        'updated' => now()->subHour()->toIso8601String(),
        'content' => '<ul><li>FIX: CVE-2026-1234 stored XSS on the third party card</li></ul>',
    ]]);

    $this->artisan('dolinews:watch-dolibarr-releases', ['--since' => now()->subWeek()->toDateString()])
        ->assertSuccessful();

    // A patch release would be bugfix_minor: the changelog decides, and
    // the focus is what sends the security mails (SPEC 6.4).
    expect(Article::query()->firstOrFail()->focus)->toBe(Focus::SECURITY);
});

it('reproduces nothing of the release note when it writes by itself', function (): void {
    watchedProject();
    fakeFeed([[
        'tag' => '21.0.1',
        'updated' => now()->subHour()->toIso8601String(),
        'content' => '<ul><li>FIX: une phrase bien reconnaissable du journal amont</li></ul>',
    ]]);

    $this->artisan('dolinews:watch-dolibarr-releases', ['--since' => now()->subWeek()->toDateString()])
        ->assertSuccessful();

    $article = Article::query()->firstOrFail();

    // The note belongs to its authors, under the licence of their own
    // project and not under the one this service publishes (D15): the
    // facts are stated, the text is linked to.
    expect($article->body)->not->toContain('une phrase bien reconnaissable')
        ->and($article->body)->toContain('https://forge.test/Dolibarr/dolibarr/releases/tag/21.0.1');
});

it('hands the writing endpoint the constraint it must not break', function (): void {
    watchedProject();
    config([
        'dolinews.releases.writer.endpoint' => 'https://redaction.test',
        'dolinews.releases.writer.token' => 'jeton',
    ]);

    Http::fake([
        'redaction.test/*' => Http::response([
            'success' => true,
            'article' => [
                'title' => 'Dolibarr 21.0.1 corrige la fiche tiers',
                'summary' => 'Une version de maintenance.',
                'body' => "## Ce qui change\n\nUn correctif sur la fiche tiers.",
            ],
        ]),
        '*' => Http::response(
            releaseFeed([['tag' => '21.0.1', 'updated' => now()->subHour()->toIso8601String()]]),
            200,
        ),
    ]);

    $this->artisan('dolinews:watch-dolibarr-releases', ['--since' => now()->subWeek()->toDateString()])
        ->assertSuccessful();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'redaction.test')) {
            return false;
        }

        return $request['instructions'] === ProxyReleaseWriter::instructions('fr_FR')
            && str_contains((string) $request['instructions'], 'Ne jamais reproduire');
    });

    expect(Article::query()->firstOrFail()->title)->toBe('Dolibarr 21.0.1 corrige la fiche tiers');
});

it('falls back to the facts when the endpoint answers nothing usable', function (): void {
    watchedProject();
    config([
        'dolinews.releases.writer.endpoint' => 'https://redaction.test',
        'dolinews.releases.writer.token' => 'jeton',
    ]);

    Http::fake([
        'redaction.test/*' => Http::response(['success' => false], 500),
        '*' => Http::response(
            releaseFeed([['tag' => '21.0.1', 'updated' => now()->subHour()->toIso8601String()]]),
            200,
        ),
    ]);

    // Silence is the one answer that is not acceptable: the reader has
    // to be told the release exists.
    $this->artisan('dolinews:watch-dolibarr-releases', ['--since' => now()->subWeek()->toDateString()])
        ->assertSuccessful();

    expect(Article::query()->firstOrFail()->title)->toBe('Dolibarr 21.0.1');
});

it('stops on a quota refusal without leaving a draft behind', function (): void {
    $project = watchedProject();
    $author = User::query()->where('email', 'veille@dolinews.test')->firstOrFail();

    config(['dolinews.quota.queue_ceiling' => 1]);

    // One announcement of the same editor already sits in the queue.
    $pending = Factory::article($author, ['title' => 'Autre annonce', 'version' => '1.0.0']);
    app(ArticleService::class)->submit($pending, $author);

    fakeFeed([['tag' => '21.0.1', 'updated' => now()->subHour()->toIso8601String()]]);

    $this->artisan('dolinews:watch-dolibarr-releases', ['--since' => now()->subWeek()->toDateString()])
        ->assertSuccessful();

    // Nothing of the release survives the refusal, draft included: a
    // left-over draft would be announced twice the day the queue clears.
    expect(Article::query()->where('project_id', $project->getKey())->count())->toBe(0);
});

it('bounds what one run submits', function (): void {
    watchedProject();
    fakeFeed([
        ['tag' => '21.0.1', 'updated' => now()->subHours(1)->toIso8601String()],
        ['tag' => '20.0.4', 'updated' => now()->subHours(2)->toIso8601String()],
        ['tag' => '19.0.6', 'updated' => now()->subHours(3)->toIso8601String()],
    ]);

    $this->artisan('dolinews:watch-dolibarr-releases', [
        '--since' => now()->subWeek()->toDateString(),
        '--limit' => 2,
    ])->assertSuccessful();

    // Oldest first, so what is left over is still in the feed tomorrow.
    expect(Article::query()->pluck('version')->sort()->values()->all())->toBe(['19.0.6', '20.0.4']);
});

it('writes nothing on a dry run', function (): void {
    watchedProject();
    fakeFeed([['tag' => '21.0.1', 'updated' => now()->subHour()->toIso8601String()]]);

    $this->artisan('dolinews:watch-dolibarr-releases', [
        '--since' => now()->subWeek()->toDateString(),
        '--dry-run' => true,
    ])->expectsOutputToContain('21.0.1')->assertSuccessful();

    expect(Article::query()->count())->toBe(0);
});

it('stays idle and silent when nothing is configured', function (): void {
    config(['dolinews.releases.project' => '', 'dolinews.releases.author_email' => '']);

    // An unconfigured watch is an intended state, not a failed run: the
    // scheduler must not report it every morning.
    $this->artisan('dolinews:watch-dolibarr-releases')->assertSuccessful();

    expect(Article::query()->count())->toBe(0);
});

it('reports a misconfigured sheet as a failure', function (): void {
    config([
        'dolinews.releases.project' => 'fiche-inexistante',
        'dolinews.releases.author_email' => 'personne@dolinews.test',
    ]);

    $this->artisan('dolinews:watch-dolibarr-releases')->assertFailed();
});

it('carries the drafting mention on the published page', function (): void {
    $project = watchedProject();
    $author = User::query()->where('email', 'veille@dolinews.test')->firstOrFail();

    $article = Factory::publishedArticle($author, [
        'project_id' => $project->getKey(),
        'title' => 'Dolibarr 21.0.1',
        'version' => '21.0.1',
    ]);
    $article->auto_drafted = true;
    $article->save();

    // The reader is told how the text in front of them came to be.
    $this->get(ArticleUrl::for($article))
        ->assertOk()
        ->assertSee('Texte établi automatiquement', escape: false);
});

<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleException;
use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Articles\RevisionService;
use App\Domain\Dolinews\Models\Article;
use Tests\Support\Factory;

/**
 * The two paths an author has to file an announcement published without
 * its sheet: the revision, which the review sees, and the bulk rescue
 * command the operator runs on the instance.
 */
it('carries the sheet through a revision, from the API to the review', function (): void {
    $author = Factory::contributorWithoutEditor();
    $article = Factory::publishedArticle($author);
    $sheet = Factory::projectFor($author);

    $token = Factory::apiToken($author);

    $response = $this->withToken($token)->postJson('/api/v1/articles/'.$article->getKey().'/revisions', [
        'project_id' => $sheet->getKey(),
        'motive' => 'Annonce déposée avant la création de la fiche.',
    ]);

    $response->assertCreated();

    expect($response->json('data.changed_fields'))->toContain('project_id')
        // Nothing moves before the review decides: a revision is a
        // proposal (SPEC 5.4).
        ->and($article->fresh()?->project_id)->toBeNull();

    $revisions = app(RevisionService::class);
    $revisions->apply($revisions->pendingRevision($article->fresh()));

    expect($article->fresh()?->project_id)->toBe($sheet->getKey());
});

it('keeps the original sheet in the revision snapshot', function (): void {
    $author = Factory::contributorWithoutEditor();
    $article = Factory::publishedArticle($author);
    $first = Factory::projectFor($author, ['name' => 'Première fiche']);
    $second = Factory::projectFor($author, ['name' => 'Seconde fiche']);

    app(ArticleService::class)->linkProject($article, $first);

    $revision = app(RevisionService::class)->propose(
        $article->fresh(),
        $author,
        ['project_id' => $second->getKey()],
        'Annonce rangée sous la mauvaise fiche.',
    );

    // The complete state before application is kept, the sheet included:
    // the original stays consultable as it was (SPEC 5.4).
    expect($revision->snapshot)->toHaveKey('project_id')
        ->and($revision->snapshot['project_id'])->toBe($first->getKey());
});

it('refuses a revision naming a sheet of another editor', function (): void {
    $author = Factory::contributorWithoutEditor();
    $stranger = Factory::contributorWithoutEditor();

    $article = Factory::publishedArticle($author);
    $theirSheet = Factory::projectFor($stranger);

    // Refused on proposal, not on application: a revision the sheet will
    // reject would otherwise wait in the queue for a moderator to read it
    // before discovering it cannot apply.
    expect(fn () => app(RevisionService::class)->propose(
        $article,
        $author,
        ['project_id' => $theirSheet->getKey()],
        'Tentative de rattachement sous une fiche étrangère.',
    ))->toThrow(ArticleException::class);
});

it('takes inventory of the announcements with no sheet, and changes nothing', function (): void {
    $author = Factory::contributorWithoutEditor();
    $article = Factory::publishedArticle($author, ['title' => 'Module orphelin 1.0']);
    Factory::projectFor($author);

    $this->artisan('dolinews:link-articles')
        ->expectsOutputToContain('1 annonce(s) sans fiche')
        ->assertSuccessful();

    expect($article->fresh()?->project_id)->toBeNull();
});

it('files under the only sheet of an editor', function (): void {
    $author = Factory::contributorWithoutEditor();
    $article = Factory::publishedArticle($author);
    $sheet = Factory::projectFor($author);

    $this->artisan('dolinews:link-articles', ['--auto' => true])->assertSuccessful();

    expect($article->fresh()?->project_id)->toBe($sheet->getKey());
});

it('leaves an ambiguous announcement alone, and says so', function (): void {
    $author = Factory::contributorWithoutEditor();
    $article = Factory::publishedArticle($author);
    Factory::projectFor($author, ['name' => 'Premier module']);
    Factory::projectFor($author, ['name' => 'Second module']);

    // Two sheets, so no obvious destination: the command does not pick
    // one, it reports the announcement as needing a --map entry.
    $this->artisan('dolinews:link-articles', ['--auto' => true])
        ->expectsOutputToContain('aucune destination')
        ->assertSuccessful();

    expect($article->fresh()?->project_id)->toBeNull();
});

it('files where a map says, and simulates without writing', function (): void {
    $author = Factory::contributorWithoutEditor();
    $article = Factory::publishedArticle($author);
    Factory::projectFor($author, ['name' => 'Premier module']);
    $target = Factory::projectFor($author, ['name' => 'Second module']);

    $map = tempnam(sys_get_temp_dir(), 'map').'.json';
    file_put_contents($map, json_encode([(string) $article->getKey() => $target->slug]));

    $this->artisan('dolinews:link-articles', ['--map' => $map, '--dry-run' => true])->assertSuccessful();

    expect($article->fresh()?->project_id)->toBeNull();

    $this->artisan('dolinews:link-articles', ['--map' => $map])->assertSuccessful();

    expect($article->fresh()?->project_id)->toBe($target->getKey());

    unlink($map);
});

it('realigns a group filed halfway', function (): void {
    $author = Factory::contributorWithoutEditor();
    $source = Factory::publishedArticle($author);
    $translation = Factory::publishedTranslation($author, $source, 'es_ES');
    $sheet = Factory::projectFor($author);

    // The state a partial repair leaves behind: the source carries the
    // sheet, its translation does not.
    $source->forceFill(['project_id' => $sheet->getKey()])->save();

    expect($translation->fresh()?->project_id)->toBeNull();

    $this->artisan('dolinews:link-articles', ['--auto' => true])->assertSuccessful();

    expect($translation->fresh()?->project_id)->toBe($sheet->getKey());
});

it('stops on an unreadable map rather than filing half an archive', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'map').'.json';
    file_put_contents($path, 'pas du json');

    $this->artisan('dolinews:link-articles', ['--map' => $path])->assertFailed();

    unlink($path);
});

it('reports a destination no sheet answers to', function (): void {
    $author = Factory::contributorWithoutEditor();
    $article = Factory::publishedArticle($author);
    Factory::projectFor($author);

    $map = tempnam(sys_get_temp_dir(), 'map').'.json';
    file_put_contents($map, json_encode([(string) $article->getKey() => 'fiche-inexistante']));

    $this->artisan('dolinews:link-articles', ['--map' => $map])
        ->expectsOutputToContain('Fiche inconnue')
        ->assertSuccessful();

    expect($article->fresh()?->project_id)->toBeNull();

    unlink($map);
});

it('files nothing twice', function (): void {
    $author = Factory::contributorWithoutEditor();
    $article = Factory::publishedArticle($author);
    Factory::projectFor($author);

    $this->artisan('dolinews:link-articles', ['--auto' => true])->assertSuccessful();

    $filedAt = Article::query()->findOrFail($article->getKey())->updated_at;

    $this->artisan('dolinews:link-articles', ['--auto' => true])
        ->expectsOutputToContain('Aucune annonce sans fiche')
        ->assertSuccessful();

    expect(Article::query()->findOrFail($article->getKey())->updated_at?->eq($filedAt))->toBeTrue();
});

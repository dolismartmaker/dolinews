<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Articles\TranslationService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\ModerationLog;
use App\Domain\Dolinews\Projects\ProjectService;
use App\Domain\Dolinews\Review\ReviewService;
use App\Models\User;
use Tests\Support\Factory;

/**
 * Batch publication under the first-version date (SPEC 5.1), driven by
 * the manifest of scripts/publish-caprel-catalog.php.
 */
function manifestFile(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'manifest').'.json';
    file_put_contents($path, (string) json_encode($entries));

    return $path;
}

it('publishes a submitted article under its first-version date', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);
    app(ArticleService::class)->submit($article, $admin);

    $path = manifestFile(['captodo' => [
        'submitted_article_id' => $article->getKey(),
        'first_release_date' => '2024-06-11',
    ]]);

    $this->artisan('dolinews:publish-backdated', ['manifest' => $path])
        ->assertSuccessful();

    $article = $article->refresh();

    expect($article->status)->toBe(ArticleStatus::PUBLISHED)
        ->and($article->published_at?->format('Y-m-d'))->toBe('2024-06-11')
        ->and($article->isBackdated())->toBeTrue();

    unlink($path);
});

it('changes nothing on a dry run', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);
    app(ArticleService::class)->submit($article, $admin);

    $path = manifestFile(['captodo' => [
        'submitted_article_id' => $article->getKey(),
        'first_release_date' => '2024-06-11',
    ]]);

    $this->artisan('dolinews:publish-backdated', ['manifest' => $path, '--dry-run' => true])
        ->assertSuccessful();

    expect($article->refresh()->status)->toBe(ArticleStatus::PENDING);

    unlink($path);
});

it('skips an entry without a first-version date', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);
    app(ArticleService::class)->submit($article, $admin);

    // Publishing it at today would silently put it at the top of the
    // feed: the one outcome the command exists to avoid.
    $path = manifestFile(['captodo' => [
        'submitted_article_id' => $article->getKey(),
        'first_release_date' => null,
    ]]);

    $this->artisan('dolinews:publish-backdated', ['manifest' => $path])
        ->assertSuccessful();

    expect($article->refresh()->status)->toBe(ArticleStatus::PENDING);

    unlink($path);
});

it('leaves an article that is not awaiting review alone', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);

    // Never submitted: still a draft.
    $path = manifestFile(['captodo' => [
        'submitted_article_id' => $article->getKey(),
        'first_release_date' => '2024-06-11',
    ]]);

    $this->artisan('dolinews:publish-backdated', ['manifest' => $path])
        ->assertSuccessful();

    expect($article->refresh()->status)->toBe(ArticleStatus::DRAFT);

    unlink($path);
});

it('refuses to guess between several super admins', function (): void {
    User::factory()->count(2)->superAdmin()->create();

    $path = manifestFile([]);

    $this->artisan('dolinews:publish-backdated', ['manifest' => $path])
        ->assertFailed();

    unlink($path);
});

it('fails on a missing manifest', function (): void {
    User::factory()->superAdmin()->create();

    $this->artisan('dolinews:publish-backdated', ['manifest' => '/tmp/absent-manifest.json'])
        ->assertFailed();
});

it('corrects the date of an article already published at its moderation date', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);
    app(ArticleService::class)->submit($article, $admin);

    // The state the archives are actually in: published before
    // back-dating existed, so carrying the day the review accepted them.
    app(ReviewService::class)->publishByAdmin($article, $admin, 'amorçage');
    expect($article->refresh()->isBackdated())->toBeFalse();

    $path = manifestFile(['smartinterventions' => [
        'submitted_article_id' => $article->getKey(),
        'first_release_date' => '2023-09-26',
    ]]);

    $this->artisan('dolinews:publish-backdated', ['manifest' => $path])
        ->assertSuccessful();

    $article = $article->refresh();

    expect($article->published_at?->format('Y-m-d'))->toBe('2023-09-26')
        ->and($article->isBackdated())->toBeTrue();

    unlink($path);
});

it('changes nothing on a second run', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);
    app(ArticleService::class)->submit($article, $admin);

    $path = manifestFile(['smartinterventions' => [
        'submitted_article_id' => $article->getKey(),
        'first_release_date' => '2023-09-26',
    ]]);

    $this->artisan('dolinews:publish-backdated', ['manifest' => $path])->assertSuccessful();
    $entriesAfterFirst = ModerationLog::query()->where('article_id', $article->getKey())->count();

    $this->artisan('dolinews:publish-backdated', ['manifest' => $path])
        ->expectsOutputToContain('inchangé')
        ->assertSuccessful();

    expect($article->refresh()->published_at?->format('Y-m-d'))->toBe('2023-09-26')
        // An idempotent run writes no act: a journal filling up with
        // corrections that corrected nothing hides the ones that did.
        ->and(ModerationLog::query()->where('article_id', $article->getKey())->count())
        ->toBe($entriesAfterFirst);

    unlink($path);
});

it('finds an archive by project slug and version when no id was recorded', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $editor = Factory::editorFor($admin);
    $project = app(ProjectService::class)
        ->create($editor, ['name' => 'SmartInterventions', 'summary' => 'Un module.']);

    $article = Factory::article($admin, [
        'project_id' => $project->getKey(),
        'version' => '1.0.4',
    ]);
    app(ArticleService::class)->submit($article, $admin);
    app(ReviewService::class)->publishByAdmin($article, $admin, 'amorçage');

    // The three inline scripts predate any manifest: nothing wrote their
    // article ids down, only the project and the version are known.
    $path = manifestFile(['smartinterventions-1.0' => [
        'project' => 'smartinterventions',
        'version' => '1.0.4',
        'first_release_date' => '2023-09-26',
    ]]);

    $this->artisan('dolinews:publish-backdated', ['manifest' => $path])
        ->assertSuccessful();

    expect($article->refresh()->published_at?->format('Y-m-d'))->toBe('2023-09-26');

    unlink($path);
});

it('carries the translation to the same date as its source', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $source = Factory::article($admin, ['version' => '2.0.8']);
    app(ArticleService::class)->submit($source, $admin);
    app(ReviewService::class)->publishByAdmin($source, $admin, 'amorçage');

    $translation = app(TranslationService::class)->submitTranslation($source, $admin, 'en_US', [
        'title' => 'Module XY 2.1',
        'summary' => 'Security fix.',
        'body' => '## Details',
    ]);
    app(ArticleService::class)->submit($translation, $admin);
    app(ReviewService::class)->publishByAdmin($translation, $admin, 'amorçage');

    $path = manifestFile(['smartinterventions-2.0' => [
        'submitted_article_id' => $source->getKey(),
        'first_release_date' => '2025-07-23',
    ]]);

    $this->artisan('dolinews:publish-backdated', ['manifest' => $path])
        ->assertSuccessful();

    // A translation announces the same dated event as its source: leaving
    // it at today would split one release across two years of the feed.
    expect($source->refresh()->published_at?->format('Y-m-d'))->toBe('2025-07-23')
        ->and($translation->refresh()->published_at?->format('Y-m-d'))->toBe('2025-07-23');

    unlink($path);
});

it('ships an archives manifest every entry of which is usable', function (): void {
    $path = base_path('scripts/archives-historiques.json');

    /** @var array<string, mixed> $manifest */
    $manifest = json_decode((string) file_get_contents($path), true);

    $entries = array_filter(
        $manifest,
        static fn (string $slug): bool => ! str_starts_with($slug, '_'),
        ARRAY_FILTER_USE_KEY,
    );

    expect($entries)->not->toBeEmpty();

    foreach ($entries as $slug => $entry) {
        // A shipped manifest with a missing date is a correction that
        // silently does nothing, discovered on the instance and nowhere
        // else.
        expect($entry)->toHaveKeys(['project', 'version', 'first_release_date'], $slug)
            ->and($entry['first_release_date'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    }
});

it('ignores the underscored keys a hand-written manifest carries', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $article = Factory::article($admin);
    app(ArticleService::class)->submit($article, $admin);

    $path = manifestFile([
        '_lisez-moi' => ['JSON n\'a pas de commentaires.'],
        'captodo' => [
            'submitted_article_id' => $article->getKey(),
            'first_release_date' => '2024-06-11',
        ],
    ]);

    $this->artisan('dolinews:publish-backdated', ['manifest' => $path])
        ->assertSuccessful();

    expect($article->refresh()->published_at?->format('Y-m-d'))->toBe('2024-06-11');

    unlink($path);
});

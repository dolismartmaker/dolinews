<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Enums\ArticleStatus;
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

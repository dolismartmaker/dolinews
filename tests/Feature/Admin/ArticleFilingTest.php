<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditEntry;
use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Models\Article;
use App\Livewire\Admin\ArticleList;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * Filing a published announcement under its project sheet.
 *
 * The rescue of an announcement deposited without its sheet: it reads
 * correctly but is ranged nowhere, absent from the sheet of the module
 * it announces. Filing changes no word of it, so it skips the review
 * and carries no correction mention - which is what distinguishes it
 * from a revision (SPEC 5.4).
 */
it('files a published announcement under a sheet, without a review', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $author = Factory::contributorWithoutEditor();

    $article = Factory::publishedArticle($author);
    $sheet = Factory::projectFor($author);

    expect($article->project_id)->toBeNull();

    $revisionNumber = $article->revision_number;

    Livewire::actingAs($admin)
        ->test(ArticleList::class)
        ->call('openAct', $article->getKey())
        ->set('linkProjectId', (string) $sheet->getKey())
        ->set('linkMotive', 'Annonce déposée avant la création de la fiche du module.')
        ->call('linkProject')
        ->assertHasNoErrors();

    $filed = $article->fresh();

    expect($filed?->project_id)->toBe($sheet->getKey())
        // Not a correction: no revision number moves, so no mention
        // appears on the published announcement.
        ->and($filed?->revision_number)->toBe($revisionNumber)
        ->and(AuditEntry::query()->where('action', 'article.project_attached')->count())->toBe(1);
});

it('files the language versions along with their source', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $author = Factory::contributorWithoutEditor();

    $source = Factory::publishedArticle($author);
    Factory::publishedTranslation($author, $source, 'es_ES');
    $sheet = Factory::projectFor($author);

    Livewire::actingAs($admin)
        ->test(ArticleList::class)
        ->call('openAct', $source->getKey())
        ->set('linkProjectId', (string) $sheet->getKey())
        ->set('linkMotive', 'Rattachement du groupe entier à sa fiche.')
        ->call('linkProject')
        ->assertHasNoErrors();

    // project_id is borne by each article: a sheet listing the source
    // while its translations stay out is the state to avoid.
    $group = Article::query()->where('translation_group_id', $source->translation_group_id)->get();

    expect($group)->toHaveCount(2)
        ->and($group->pluck('project_id')->unique()->all())->toBe([$sheet->getKey()]);
});

it('takes an announcement out of a sheet it should not be in', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $author = Factory::contributorWithoutEditor();

    $sheet = Factory::projectFor($author);
    $article = Factory::publishedArticle($author);

    app(ArticleService::class)->linkProject($article, $sheet);

    Livewire::actingAs($admin)
        ->test(ArticleList::class)
        ->call('openAct', $article->getKey())
        ->set('linkProjectId', '')
        ->set('linkMotive', 'Annonce rangée sous la mauvaise fiche au dépôt du catalogue.')
        ->call('linkProject')
        ->assertHasNoErrors();

    expect($article->fresh()?->project_id)->toBeNull()
        ->and(AuditEntry::query()->where('action', 'article.project_detached')->count())->toBe(1);
});

it('refuses a sheet belonging to another editor', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $author = Factory::contributorWithoutEditor();
    $stranger = Factory::contributorWithoutEditor();

    $article = Factory::publishedArticle($author);
    $theirSheet = Factory::projectFor($stranger);

    // Filing under someone else's sheet is a claim, which has its own
    // circuit (SPEC 9.5).
    Livewire::actingAs($admin)
        ->test(ArticleList::class)
        ->call('openAct', $article->getKey())
        ->set('linkProjectId', (string) $theirSheet->getKey())
        ->set('linkMotive', 'Tentative de rattachement sous une fiche étrangère.')
        ->call('linkProject')
        ->assertHasErrors('linkProjectId');

    expect($article->fresh()?->project_id)->toBeNull();
});

it('refuses a filing that would collide with a slug already there', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $author = Factory::contributorWithoutEditor();

    $sheet = Factory::projectFor($author);

    $alreadyThere = Factory::publishedArticle($author, ['title' => 'Module XY 3.0']);
    app(ArticleService::class)->linkProject($alreadyThere, $sheet);

    $orphan = Factory::publishedArticle($author, ['title' => 'Module XY 3.0']);

    // (project_id, slug) is unique, and the slug is part of the API
    // contract: it is never silently rewritten to make room.
    Livewire::actingAs($admin)
        ->test(ArticleList::class)
        ->call('openAct', $orphan->getKey())
        ->set('linkProjectId', (string) $sheet->getKey())
        ->set('linkMotive', 'Rattachement qui entre en collision de slug.')
        ->call('linkProject')
        ->assertHasErrors('linkProjectId');

    expect($orphan->fresh()?->project_id)->toBeNull();
});

it('asks for a motive before filing', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $author = Factory::contributorWithoutEditor();

    $article = Factory::publishedArticle($author);
    $sheet = Factory::projectFor($author);

    Livewire::actingAs($admin)
        ->test(ArticleList::class)
        ->call('openAct', $article->getKey())
        ->set('linkProjectId', (string) $sheet->getKey())
        ->set('linkMotive', '')
        ->call('linkProject')
        ->assertHasErrors('linkMotive');

    expect($article->fresh()?->project_id)->toBeNull();
});

it('leaves the filing to the operator', function (): void {
    $moderator = User::factory()->moderator()->create(['email_verified_at' => now()]);
    $author = Factory::contributorWithoutEditor();

    $article = Factory::publishedArticle($author);
    $sheet = Factory::projectFor($author);

    // Moderators hide, withdraw and restore from this screen (SPEC 9.3);
    // ranging the archive is an exploitation matter.
    Livewire::actingAs($moderator)
        ->test(ArticleList::class)
        ->call('openAct', $article->getKey())
        ->set('linkProjectId', (string) $sheet->getKey())
        ->set('linkMotive', 'Motif suffisamment long pour passer la validation.')
        ->call('linkProject')
        ->assertForbidden();

    expect($article->fresh()?->project_id)->toBeNull();
});

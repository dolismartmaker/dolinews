<?php

declare(strict_types=1);

use App\Livewire\Admin\ApiRequestList;
use App\Livewire\Admin\ArticleList;
use App\Livewire\Admin\EditorList;
use App\Livewire\Admin\MediaList;
use App\Livewire\Admin\ModerationLogList;
use App\Livewire\Admin\ProjectList;
use App\Livewire\Admin\ReportList;
use App\Livewire\Admin\ReviewQueue;
use App\Livewire\Admin\UserList;
use App\Models\User;
use Caprel\Admin\Testing\AssertsAdminConventions;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * The conventions of the shared back-office (caprel/laravel-admin), so that
 * the next screen cannot be added without them.
 *
 * No list here carries a trash: nothing in DoliNews is soft-deleted from a
 * list, a withdrawal is a moderation act with its rule and its motive.
 */
uses(AssertsAdminConventions::class);

const ADMIN_LISTS = [
    ApiRequestList::class,
    ArticleList::class,
    EditorList::class,
    MediaList::class,
    ModerationLogList::class,
    ProjectList::class,
    ReportList::class,
    ReviewQueue::class,
    UserList::class,
];

it('offers a selection on every list', function (): void {
    $this->assertListsOfferASelection(ADMIN_LISTS);
});

it('points every menu entry to an existing route', function (): void {
    $this->assertNavigationRoutesExist();
});

it('loads no javascript of the service in the back-office', function (): void {
    $this->assertAdminLoadsNoJavaScript();
});

it('exports the selected rows of a list as CSV', function (): void {
    $author = User::factory()->create(['email' => 'auteur@example.org']);
    $article = Factory::article($author, ['title' => 'Module exporté 1.0']);

    $response = Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ArticleList::class)
        ->set('selected', [(string) $article->getKey()])
        ->call('exportSelected');

    $response->assertFileDownloaded();
    expect($response->get('selected'))->toBe([]);
});

it('never exports a row the screen does not show', function (): void {
    $moderator = User::factory()->moderator()->create();

    // A key from the browser that the query of the screen does not hold: it
    // comes back as nothing selected, not as a file.
    Livewire::actingAs($moderator)
        ->test(ArticleList::class)
        ->set('selected', ['999999'])
        ->call('exportSelected')
        ->assertNoFileDownloaded();
});

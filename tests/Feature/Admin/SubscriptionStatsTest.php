<?php

declare(strict_types=1);

use App\Domain\Dolinews\Models\EditorWatch;
use App\Domain\Dolinews\Models\ProjectWatch;
use App\Domain\Dolinews\Models\SubscriptionLink;
use App\Domain\Dolinews\Subscriptions\SubscriptionStats;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\EditorList;
use App\Livewire\Admin\ProjectList;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * Subscription figures of the back-office (SPEC 6.4): totals for the whole
 * team, the count per sheet and per editor for the super admin alone.
 */
function subscriptionLink(string $email, ?string $usedAt, string $expiresAt): void
{
    (new SubscriptionLink)->forceFill([
        'email' => $email,
        'token' => Str::random(32),
        'purpose' => SubscriptionLink::PURPOSE_CONFIRM,
        'used_at' => $usedAt,
        'expires_at' => $expiresAt,
    ])->save();
}

it('counts the active accounts, what they follow and how they receive it', function (): void {
    $owner = User::factory()->create();
    $project = Factory::projectFor($owner);
    $editor = Factory::editorFor($owner);

    $instant = User::factory()->create(['email_digest' => 'instant', 'feed_token' => Str::random(32)]);
    User::factory()->create(['email_digest' => 'weekly', 'watches_all_security' => true]);
    User::factory()->create(['watches_all' => true, 'feed_token' => Str::random(32)]);
    // Unverified: followed, but the sweep mails nothing to it.
    $unverified = User::factory()->unverified()->create(['email_digest' => 'daily']);
    $suspended = User::factory()->suspended()->create([
        'email_digest' => 'daily',
        'watches_all' => true,
        'feed_token' => Str::random(32),
    ]);

    ProjectWatch::query()->create(['user_id' => $instant->getKey(), 'project_id' => $project->getKey()]);
    ProjectWatch::query()->create(['user_id' => $unverified->getKey(), 'project_id' => $project->getKey()]);
    ProjectWatch::query()->create(['user_id' => $suspended->getKey(), 'project_id' => $project->getKey()]);
    EditorWatch::query()->create(['user_id' => $instant->getKey(), 'editor_id' => $editor->getKey()]);
    EditorWatch::query()->create(['user_id' => $suspended->getKey(), 'editor_id' => $editor->getKey()]);

    subscriptionLink('attente@example.test', null, now()->addDay()->toDateTimeString());
    subscriptionLink('attente@example.test', null, now()->addDays(2)->toDateTimeString());
    subscriptionLink('confirme@example.test', now()->toDateTimeString(), now()->addDay()->toDateTimeString());
    subscriptionLink('expire@example.test', null, now()->subMinute()->toDateTimeString());

    $stats = app(SubscriptionStats::class);

    // instant, weekly, feedOnly and unverified; the owner and the
    // suspended account are left out.
    expect($stats->subscribedAccounts())->toBe(4)
        ->and($stats->wholeFeedFollowers())->toBe(1)
        ->and($stats->securityFollowers())->toBe(1)
        ->and($stats->projectWatches())->toBe(2)
        ->and($stats->editorWatches())->toBe(1)
        ->and($stats->emailRecipientsByCadence())->toBe(['instant' => 1, 'daily' => 0, 'weekly' => 1])
        ->and($stats->personalFeeds())->toBe(2)
        // One address, two open links: one subscription that may yet exist.
        ->and($stats->pendingConfirmations())->toBe(1);
});

it('shows the subscription totals to a moderator on the dashboard', function (): void {
    User::factory()->create(['email_digest' => 'daily', 'watches_all' => true]);

    $component = Livewire::actingAs(User::factory()->moderator()->create())
        ->test(Dashboard::class)
        ->assertSee(__('Comptes abonnés'))
        ->assertSee(__('Un résumé par jour'));

    expect($component->viewData('subscriptions'))
        ->accounts->toBe(1)
        ->wholeFeed->toBe(1)
        ->emails->toBe(['instant' => 0, 'daily' => 1, 'weekly' => 0]);
});

it('gives the super admin a sortable follower count per sheet', function (): void {
    $owner = User::factory()->create();
    $followed = Factory::projectFor($owner);
    $quiet = Factory::projectFor($owner);

    foreach (User::factory()->count(2)->create() as $reader) {
        ProjectWatch::query()->create(['user_id' => $reader->getKey(), 'project_id' => $followed->getKey()]);
    }
    ProjectWatch::query()->create([
        'user_id' => User::factory()->suspended()->create()->getKey(),
        'project_id' => $quiet->getKey(),
    ]);

    $component = Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test(ProjectList::class)
        ->set('sortBy', ['column' => 'followers', 'direction' => 'desc']);

    expect(array_column($component->viewData('headers'), 'key'))->toContain('followers');

    $rows = $component->viewData('rows')->items();

    expect($rows[0]['id'])->toBe((string) $followed->getKey())
        ->and($rows[0]['followers'])->toBe('2')
        ->and($rows[1]['followers'])->toBe('0');
});

it('gives the super admin a follower count per editor', function (): void {
    $owner = User::factory()->create();
    $editor = Factory::editorFor($owner);

    EditorWatch::query()->create(['user_id' => User::factory()->create()->getKey(), 'editor_id' => $editor->getKey()]);

    $component = Livewire::actingAs(User::factory()->superAdmin()->create())->test(EditorList::class);

    expect(array_column($component->viewData('headers'), 'key'))->toContain('followers');

    $row = collect($component->viewData('rows')->items())->firstWhere('id', $editor->getKey());

    expect($row['followers'])->toBe('1');
});

it('keeps the count per sheet and per editor from a moderator', function (string $list): void {
    // A moderator may edit modules of their own: how many readers a
    // competitor has is not theirs to read.
    $project = Factory::projectFor(User::factory()->create());
    ProjectWatch::query()->create(['user_id' => User::factory()->create()->getKey(), 'project_id' => $project->getKey()]);

    $component = Livewire::actingAs(User::factory()->moderator()->create())
        ->test($list)
        ->set('sortBy', ['column' => 'followers', 'direction' => 'desc']);

    expect(array_column($component->viewData('headers'), 'key'))->not->toContain('followers');

    foreach ($component->viewData('rows')->items() as $row) {
        expect($row)->not->toHaveKey('followers');
    }
})->with(['sheets' => ProjectList::class, 'editors' => EditorList::class]);

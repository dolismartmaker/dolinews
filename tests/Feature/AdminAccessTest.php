<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditEntry;
use App\Domain\Dolinews\Articles\ArticleService;
use App\Livewire\Admin\UserList;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * Admin back-office gating (socle section 6) and the review queue
 * screens.
 */
it('redirects guests to the login screen', function (): void {
    $this->get('/admin')->assertRedirect(route('login'));
});

it('refuses plain accounts with a 403', function (): void {
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
});

it('admits moderators on every screen', function (string $uri): void {
    $this->actingAs(User::factory()->moderator()->create())->get($uri)->assertOk();
})->with([
    '/admin',
    '/admin/users',
    '/admin/editors',
    '/admin/projects',
    '/admin/articles',
    '/admin/review',
    '/admin/moderation',
    '/admin/media',
    '/admin/api-requests',
]);

it('shows the review queue to the team', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    $this->actingAs(User::factory()->moderator()->create())
        ->get('/admin/review')
        ->assertOk()
        ->assertSee('Module XY 2.1');
});

it('shows one review thread to the team', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    $this->actingAs(User::factory()->moderator()->create())
        ->get('/admin/review/'.$article->getKey())
        ->assertOk()
        ->assertSee('Fil de revue');
});

it('denies the back-office to an unverified moderator', function (): void {
    $moderator = User::factory()->moderator()->unverified()->create();

    $this->actingAs($moderator)->get('/admin')->assertForbidden();
});

it('logs the back-office out to the public feed', function (): void {
    // There is no admin login screen to come back to: one login serves
    // readers, authors and moderators alike, so this lands on the feed.
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->post('/admin/logout')
        ->assertRedirect(route('home'));

    $this->assertGuest();
});

it('lets the super admin impersonate an account from the accounts list', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $target = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('switchTo', $target->getKey())
        ->assertRedirect(route('account.show'));

    $this->assertAuthenticatedAs($target);

    // Traced in the audit table of the service, naming the operator: the
    // session is already the target's when the entry is written.
    $trace = AuditEntry::query()->where('action', 'admin.impersonate')->sole();
    expect($trace->meta['actor_id'])->toBe($admin->getKey())
        ->and($trace->meta['impersonated'])->toBe($target->getKey());
});

it('never swaps the session on a GET', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $target = User::factory()->create();

    // What an <img> tag on a third-party page would reach: no such route.
    $this->actingAs($admin)
        ->get('/admin/impersonate/take/'.$target->getKey())
        ->assertNotFound();

    $this->assertAuthenticatedAs($admin);
});

it('refuses the impersonation to a plain moderator', function (): void {
    $moderator = User::factory()->moderator()->create();
    $target = User::factory()->create();

    Livewire::actingAs($moderator)
        ->test(UserList::class)
        ->call('switchTo', $target->getKey())
        ->assertForbidden();

    $this->assertAuthenticatedAs($moderator);
});

it('never impersonates a member of the moderation team', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $moderator = User::factory()->moderator()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('switchTo', $moderator->getKey())
        ->assertForbidden();

    $this->assertAuthenticatedAs($admin);
});

it('gives the super admin its session back when the impersonation is left', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $target = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('switchTo', $target->getKey());

    $this->post(route('admin.impersonation.leave'))
        ->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($admin);
    expect(AuditEntry::query()->where('action', 'admin.leaveImpersonation')->exists())->toBeTrue();
});

it('shows the way back on the public pages during an impersonation', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $target = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('switchTo', $target->getKey());

    $this->get(route('home'))
        ->assertOk()
        ->assertSee(route('admin.impersonation.leave'), false);
});

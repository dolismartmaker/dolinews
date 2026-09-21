<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Models\User;
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

it('impersonation stays reserved to the super admin', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->post('/admin/impersonate/take/'.$target->getKey())
        ->assertRedirect();

    // The session is now the target: the admin back-office stays
    // reachable through the impersonator stored in the manager.
    $this->assertAuthenticatedAs($target);
});

it('never swaps the session on a GET', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $target = User::factory()->create();

    // What an <img> tag on a third-party page would reach.
    $this->actingAs($admin)
        ->get('/admin/impersonate/take/'.$target->getKey())
        ->assertStatus(405);

    $this->assertAuthenticatedAs($admin);
});

it('refuses the impersonation POST to a plain moderator', function (): void {
    $moderator = User::factory()->moderator()->create();
    $target = User::factory()->create();

    $this->actingAs($moderator)
        ->post('/admin/impersonate/take/'.$target->getKey())
        ->assertForbidden();

    $this->assertAuthenticatedAs($moderator);
});

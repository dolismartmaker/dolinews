<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Contributors\ContributorVerificationService;
use App\Domain\Dolinews\Models\KnownCommitterHash;
use App\Domain\Dolinews\Support\CommitterEmailHasher;
use App\Models\User;
use App\Notifications\AccountCreated;
use App\Notifications\ArticleSubmitted;
use App\Notifications\ContributorQualified;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Factory;

/**
 * Operator notices: account creation and contributor qualification to
 * the super admins, article submission to the review team.
 */
it('tells the super admins about a fresh reader account', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $moderator = User::factory()->moderator()->create();

    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'Lectrice',
        'email' => 'lectrice@example.com',
        'password' => 'mot-de-passe-long',
        'password_confirmation' => 'mot-de-passe-long',
    ])->assertRedirect(route('verification.notice'));

    Notification::assertSentTo($admin, AccountCreated::class, function (AccountCreated $notice): bool {
        return $notice->account->email === 'lectrice@example.com';
    });

    // A reader account carries nothing to review: the team stays out.
    Notification::assertNotSentTo($moderator, AccountCreated::class);
});

it('leaves a suspended super admin out of the operator notices', function (): void {
    $suspended = User::factory()->superAdmin()->suspended()->create();

    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'Lecteur',
        'email' => 'lecteur@example.com',
        'password' => 'mot-de-passe-long',
        'password_confirmation' => 'mot-de-passe-long',
    ]);

    Notification::assertNotSentTo($suspended, AccountCreated::class);
});

it('tells the super admins when an account becomes contributor', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $candidate = User::factory()->create(['name' => 'Contributrice']);

    Notification::fake();

    app(ContributorVerificationService::class)->grantManual($candidate, 'dev@example.com');

    Notification::assertSentTo($admin, ContributorQualified::class, function (ContributorQualified $notice) use ($candidate): bool {
        return $notice->account->is($candidate);
    });
});

it('announces the passage to contributor once, not every proof', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $candidate = User::factory()->create();

    $verification = app(ContributorVerificationService::class);
    $verification->grantManual($candidate, 'first@example.com');

    Notification::fake();

    // A second commit address on an account that already writes changes
    // nothing to its class.
    $verification->grantManual($candidate, 'second@example.com');

    Notification::assertNotSentTo($admin, ContributorQualified::class);
});

it('stays silent during the catch-up pass over verified accounts', function (): void {
    $admin = User::factory()->superAdmin()->create();

    $candidate = User::factory()->create(['email' => 'known@example.com']);

    KnownCommitterHash::query()->create([
        'email_hash' => CommitterEmailHasher::hash('known@example.com'),
        'source_repo' => 'https://example.test/repo.git',
        'commit_count' => 12,
        'last_seen_at' => now(),
    ]);

    Notification::fake();

    $linked = app(ContributorVerificationService::class)->linkVerifiedAccounts();

    expect($linked)->toBe(1)
        ->and($candidate->fresh()->isContributor())->toBeTrue();

    Notification::assertNotSentTo($admin, ContributorQualified::class);
});

it('tells the review team about a submitted article', function (): void {
    $moderator = User::factory()->moderator()->create();
    $admin = User::factory()->superAdmin()->create();
    $suspended = User::factory()->moderator()->suspended()->create();
    $reader = User::factory()->create();

    $author = User::factory()->create();
    $article = Factory::article($author);

    Notification::fake();

    app(ArticleService::class)->submit($article, $author);

    Notification::assertSentTo($moderator, ArticleSubmitted::class, function (ArticleSubmitted $notice) use ($article): bool {
        return $notice->article->is($article);
    });
    Notification::assertSentTo($admin, ArticleSubmitted::class);
    Notification::assertNotSentTo($suspended, ArticleSubmitted::class);
    Notification::assertNotSentTo($reader, ArticleSubmitted::class);
    Notification::assertNotSentTo($author, ArticleSubmitted::class);
});

it('never notifies an author of their own submission, moderator or not', function (): void {
    $author = User::factory()->moderator()->create();
    $article = Factory::article($author);

    Notification::fake();

    app(ArticleService::class)->submit($article, $author);

    Notification::assertNotSentTo($author, ArticleSubmitted::class);
});

it('notifies again on resubmission, which opens a new review round', function (): void {
    $moderator = User::factory()->moderator()->create();
    $author = User::factory()->create();
    $article = Factory::article($author);

    $articles = app(ArticleService::class);
    $articles->submit($article, $author);

    Notification::fake();

    $articles->submit($article->refresh(), $author);

    Notification::assertSentToTimes($moderator, ArticleSubmitted::class, 1);
});

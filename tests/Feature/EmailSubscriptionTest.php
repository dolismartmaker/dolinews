<?php

declare(strict_types=1);

use App\Domain\Dolinews\Enums\EmailDigest;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Subscriptions\EmailSubscriptionService;
use App\Domain\Dolinews\Subscriptions\WatchService;
use App\Models\User;
use App\Notifications\SubscriptionDigest;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Factory;

/**
 * Subscription mails (SPEC 6.4).
 *
 * The tokenized feed only reaches a reader who runs a feed reader. Mail
 * reaches the Dolibarr user the service exists for, and every one of
 * them carries the way out.
 */
function subscriber(EmailDigest $digest = EmailDigest::INSTANT, array $options = []): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);

    app(EmailSubscriptionService::class)->updatePreferences($user, $digest, $options);

    // Timestamps are second-grained, and a test publishes within the
    // same second as the subscription: the clock moves on so that
    // "published after the reader subscribed" means something here.
    test()->travel(5)->seconds();

    return $user->refresh();
}

it('mails an article published after the subscription started', function (): void {
    Notification::fake();

    $reader = subscriber(EmailDigest::INSTANT, ['watches_all' => true]);

    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module XY 4.0']);

    app(EmailSubscriptionService::class)->sendDue(EmailDigest::INSTANT);

    Notification::assertSentTo($reader, SubscriptionDigest::class, function (SubscriptionDigest $mail): bool {
        return count($mail->articles) === 1 && $mail->articles[0]->title === 'Module XY 4.0';
    });
});

it('never mails the archive to a fresh subscription', function (): void {
    Notification::fake();

    // Published BEFORE the reader subscribed: the cursor starts at the
    // instant the subscription is turned on.
    Factory::publishedArticle(User::factory()->create(), ['title' => 'Annonce anterieure']);

    $reader = subscriber(EmailDigest::INSTANT, ['watches_all' => true]);

    app(EmailSubscriptionService::class)->sendDue(EmailDigest::INSTANT);

    Notification::assertNothingSentTo($reader);
});

it('mails nobody about a back-dated publication', function (): void {
    Notification::fake();

    $reader = subscriber(EmailDigest::INSTANT, ['watches_all' => true]);

    // The catalogue case (SPEC 5.1): a hundred archived versions
    // published today under their real dates must not become a hundred
    // mails. Their date lies below every cursor.
    $article = Factory::publishedArticle(User::factory()->create(), ['title' => 'Version de 2019']);
    $article->forceFill(['published_at' => now()->subYears(6)])->save();

    app(EmailSubscriptionService::class)->sendDue(EmailDigest::INSTANT);

    Notification::assertNothingSentTo($reader);
});

it('holds the cadence the reader asked for', function (): void {
    Notification::fake();

    $weekly = subscriber(EmailDigest::WEEKLY, ['watches_all' => true]);

    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module hebdo 1.0']);

    app(EmailSubscriptionService::class)->sendDue(EmailDigest::INSTANT);

    Notification::assertNothingSentTo($weekly);

    app(EmailSubscriptionService::class)->sendDue(EmailDigest::WEEKLY);

    Notification::assertSentTo($weekly, SubscriptionDigest::class);
});

it('does not mail the same digest twice in one day', function (): void {
    Notification::fake();

    $reader = subscriber(EmailDigest::DAILY, ['watches_all' => true]);

    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module A 1.0']);

    app(EmailSubscriptionService::class)->sendDue(EmailDigest::DAILY);

    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module B 1.0']);

    app(EmailSubscriptionService::class)->sendDue(EmailDigest::DAILY);

    Notification::assertSentToTimes($reader, SubscriptionDigest::class, 1);
});

it('keeps the whole-feed filter of the account', function (): void {
    Notification::fake();

    $reader = subscriber(EmailDigest::INSTANT, [
        'watches_all' => true,
        'focus' => ['security'],
    ]);

    Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Correctif de securite',
        'focus' => 'security',
    ]);

    Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Nouvelle fonction',
        'focus' => 'feature_minor',
    ]);

    app(EmailSubscriptionService::class)->sendDue(EmailDigest::INSTANT);

    Notification::assertSentTo($reader, SubscriptionDigest::class, function (SubscriptionDigest $mail): bool {
        return count($mail->articles) === 1
            && $mail->articles[0]->title === 'Correctif de securite';
    });
});

it('mails a watched project without watching the whole feed', function (): void {
    Notification::fake();

    [$author, $editor] = Factory::contributorWithEditor();

    $project = Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'module-suivi',
        'name' => 'Module suivi',
        'summary' => 'Un module.',
        'status' => 'active',
    ]);

    $reader = subscriber(EmailDigest::INSTANT);
    app(WatchService::class)->toggleProject($reader, $project);

    $watched = Factory::publishedArticle($author, ['title' => 'Sortie du module suivi']);
    $watched->forceFill(['project_id' => $project->getKey()])->save();

    Factory::publishedArticle(User::factory()->create(), ['title' => 'Un autre module']);

    app(EmailSubscriptionService::class)->sendDue(EmailDigest::INSTANT);

    Notification::assertSentTo($reader, SubscriptionDigest::class, function (SubscriptionDigest $mail): bool {
        return count($mail->articles) === 1
            && $mail->articles[0]->title === 'Sortie du module suivi';
    });
});

it('leaves a suspended or unverified account out of the run', function (): void {
    Notification::fake();

    $suspended = subscriber(EmailDigest::INSTANT, ['watches_all' => true]);
    $suspended->forceFill(['active' => false])->save();

    $unverified = subscriber(EmailDigest::INSTANT, ['watches_all' => true]);
    $unverified->forceFill(['email_verified_at' => null])->save();

    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module Z 1.0']);

    app(EmailSubscriptionService::class)->sendDue(EmailDigest::INSTANT);

    Notification::assertNothingSentTo($suspended);
    Notification::assertNothingSentTo($unverified);
});

it('sends nothing when there is nothing new', function (): void {
    Notification::fake();

    $reader = subscriber(EmailDigest::INSTANT, ['watches_all' => true]);

    app(EmailSubscriptionService::class)->sendDue(EmailDigest::INSTANT);

    Notification::assertNothingSentTo($reader);
});

it('carries the unsubscribe footer and its one-click header', function (): void {
    $reader = subscriber(EmailDigest::INSTANT, ['watches_all' => true]);

    $article = Factory::publishedArticle(User::factory()->create(), ['title' => 'Module courriel 1.0']);

    $mail = (new SubscriptionDigest([$article], EmailDigest::INSTANT))->toMail($reader);

    $rendered = (string) $mail->render();

    expect($rendered)->toContain('Vous recevez ce message parce que vous êtes abonné')
        ->and($rendered)->toContain(route('unsubscribe.show', ['token' => $reader->unsubscribe_token]))
        ->and($rendered)->toContain('Module courriel 1.0');
});

it('names the security announcement in the subject line', function (): void {
    $reader = subscriber(EmailDigest::DAILY, ['watches_all' => true]);

    $article = Factory::publishedArticle(User::factory()->create(), [
        'title' => 'Faille corrigee 2.1',
        'focus' => 'security',
    ]);

    $mail = (new SubscriptionDigest([$article], EmailDigest::DAILY))->toMail($reader);

    expect($mail->subject)->toContain('Sécurité')->toContain('Faille corrigee 2.1');
});

it('stops the mails from the footer link', function (): void {
    $reader = subscriber(EmailDigest::INSTANT, ['watches_all' => true]);

    $token = (string) $reader->unsubscribe_token;

    // The page first: a link scanner following the footer must not
    // unsubscribe anyone on its own.
    $this->get('/desabonnement/'.$token)->assertOk()->assertSee($reader->email);

    expect($reader->refresh()->email_digest)->toBe(EmailDigest::INSTANT);

    $this->post('/desabonnement/'.$token)->assertOk();

    expect($reader->refresh()->email_digest)->toBe(EmailDigest::NONE);
});

it('accepts the one-click unsubscribe without a csrf token', function (): void {
    $reader = subscriber(EmailDigest::DAILY, ['watches_all' => true]);

    // RFC 8058: the mail client posts on its own, with no session.
    $this->withMiddleware()
        ->post('/desabonnement/'.$reader->unsubscribe_token)
        ->assertOk();

    expect($reader->refresh()->email_digest)->toBe(EmailDigest::NONE);
});

it('keeps the watches and the personal feed when the mails stop', function (): void {
    [, $editor] = Factory::contributorWithEditor();

    $project = Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'module-reste',
        'name' => 'Module reste',
        'summary' => 'Un module.',
        'status' => 'active',
    ]);

    $reader = subscriber(EmailDigest::INSTANT);
    app(WatchService::class)->toggleProject($reader, $project);

    $this->post('/desabonnement/'.$reader->unsubscribe_token)->assertOk();

    expect($reader->refresh()->projectWatches()->count())->toBe(1);
});

it('refuses an unknown unsubscribe token', function (): void {
    $this->get('/desabonnement/'.str_repeat('a', 32))->assertNotFound();
});

it('sets the preferences from the account page', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->post('/account/email', [
            'email_digest' => 'daily',
            'watches_all' => '1',
            'focus' => ['security'],
        ])
        ->assertRedirect();

    $user->refresh();

    expect($user->email_digest)->toBe(EmailDigest::DAILY)
        ->and($user->watches_all)->toBeTrue()
        ->and($user->watch_all_focus_filter)->toBe(['security'])
        ->and($user->unsubscribe_token)->not->toBeNull()
        ->and($user->digest_cursor_at)->not->toBeNull();
});

it('shows the cadence on the account page', function (): void {
    $user = subscriber(EmailDigest::WEEKLY, ['watches_all' => true]);

    $this->actingAs($user)
        ->get('/account')
        ->assertOk()
        ->assertSee('Recevoir les annonces par courriel')
        ->assertSee('Un résumé par semaine', escape: false);
});

it('runs the cadence from the scheduled command', function (): void {
    Notification::fake();

    $reader = subscriber(EmailDigest::DAILY, ['watches_all' => true]);

    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module commande 1.0']);

    $this->artisan('dolinews:send-digests', ['--cadence' => 'daily'])->assertSuccessful();

    Notification::assertSentTo($reader, SubscriptionDigest::class);
});

it('refuses an unknown cadence on the command line', function (): void {
    $this->artisan('dolinews:send-digests', ['--cadence' => 'hourly'])->assertFailed();
});

it('includes the whole-feed watch in the personal rss feed', function (): void {
    $reader = subscriber(EmailDigest::NONE, ['watches_all' => true]);

    $reader->forceFill(['watches_all' => true, 'feed_token' => str_repeat('b', 32)])->save();

    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module flux global 1.0']);

    $response = $this->get('/feeds/'.str_repeat('b', 32));

    $response->assertOk();

    expect($response->getContent())->toContain('Module flux global 1.0');
});

it('leaves an article out of the mail once its cursor passed it', function (): void {
    Notification::fake();

    $reader = subscriber(EmailDigest::INSTANT, ['watches_all' => true]);

    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module unique 1.0']);

    app(EmailSubscriptionService::class)->sendDue(EmailDigest::INSTANT);
    app(EmailSubscriptionService::class)->sendDue(EmailDigest::INSTANT);

    Notification::assertSentToTimes($reader, SubscriptionDigest::class, 1);
});

it('keeps an article the reader has not been told about yet', function (): void {
    $reader = subscriber(EmailDigest::INSTANT, ['watches_all' => true]);

    Factory::publishedArticle(User::factory()->create(), ['title' => 'Module en attente 1.0']);

    $pending = app(EmailSubscriptionService::class)->pendingArticles($reader);

    expect($pending)->toHaveCount(1)
        ->and($pending[0])->toBeInstanceOf(Article::class);
});

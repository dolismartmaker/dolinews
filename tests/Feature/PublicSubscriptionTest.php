<?php

declare(strict_types=1);

use App\Domain\Dolinews\Enums\EmailDigest;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\SubscriptionLink;
use App\Domain\Dolinews\Subscriptions\EmailSubscriptionService;
use App\Models\User;
use App\Notifications\SubscriptionConfirmation;
use App\Notifications\SubscriptionDigest;
use App\Notifications\SubscriptionManageLink;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Factory;

/**
 * Subscribing with an address and nothing else (SPEC 6.4).
 *
 * The reader who runs Dolibarr will not create an account to be told
 * about a security fix. What the click protects is the other half: an
 * address belongs to whoever reads it, and a service that mails people
 * who never asked loses its deliverability once and for good.
 */
function sheetProject(): Project
{
    [$author, $editor] = Factory::contributorWithEditor();

    /** @var Project $project */
    $project = Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'module-abonnement',
        'name' => 'Module abonnement',
        'summary' => 'Un module.',
        'status' => 'active',
    ]);

    // The author is what publishes on this project later on.
    $project->setRelation('author', $author);

    return $project;
}

it('mails a confirmation and subscribes nobody yet', function (): void {
    Notification::fake();

    $project = sheetProject();

    $this->post("/fr/abonnement/projet/{$project->slug}", [
        'email' => 'lecteur@example.test',
    ])->assertRedirect();

    Notification::assertSentOnDemand(SubscriptionConfirmation::class);

    // Nothing exists until the click: the form took an address typed by
    // whoever passed by.
    expect(User::query()->where('email', 'lecteur@example.test')->exists())->toBeFalse()
        ->and(SubscriptionLink::query()->where('email', 'lecteur@example.test')->count())->toBe(1);
});

it('subscribes the address once the link is confirmed', function (): void {
    Notification::fake();

    $project = sheetProject();

    $this->post("/fr/abonnement/projet/{$project->slug}", ['email' => 'lecteur@example.test']);

    $token = (string) SubscriptionLink::query()->firstOrFail()->token;

    // The page asks for the button: a mail client's link scanner
    // follows every address it finds, and a subscription a machine can
    // confirm is a subscription nobody confirmed.
    $this->get("/fr/abonnement/{$token}")->assertOk()->assertSee('Confirmer mon abonnement');
    $this->post("/fr/abonnement/{$token}")->assertOk()->assertSee('Abonnement confirmé', escape: false);

    /** @var User $reader */
    $reader = User::query()->where('email', 'lecteur@example.test')->firstOrFail();

    expect($reader->password)->toBeNull()
        ->and($reader->email_verified_at)->not->toBeNull()
        ->and($reader->email_digest)->toBe(EmailDigest::INSTANT)
        ->and($reader->projectWatches()->where('project_id', $project->getKey())->exists())->toBeTrue();
});

it('mails the subscriber the next announcement of the project it follows', function (): void {
    Notification::fake();

    $project = sheetProject();
    /** @var User $author */
    $author = $project->getRelation('author');

    $this->post("/fr/abonnement/projet/{$project->slug}", ['email' => 'lecteur@example.test']);
    $this->post('/fr/abonnement/'.SubscriptionLink::query()->firstOrFail()->token);

    test()->travel(5)->seconds();

    $article = Factory::publishedArticle($author, ['title' => 'Module abonnement 2.0']);
    $article->forceFill(['project_id' => $project->getKey()])->save();

    app(EmailSubscriptionService::class)->sendDue(EmailDigest::INSTANT);

    /** @var User $reader */
    $reader = User::query()->where('email', 'lecteur@example.test')->firstOrFail();

    Notification::assertSentTo($reader, SubscriptionDigest::class, function (SubscriptionDigest $mail): bool {
        return count($mail->articles) === 1
            && $mail->articles[0]->title === 'Module abonnement 2.0';
    });
});

it('keeps the watch when the same link is clicked twice', function (): void {
    Notification::fake();

    $project = sheetProject();

    $this->post("/fr/abonnement/projet/{$project->slug}", ['email' => 'lecteur@example.test']);

    $token = (string) SubscriptionLink::query()->firstOrFail()->token;

    $this->post("/fr/abonnement/{$token}");
    // A second click must not toggle the watch back off: the reader who
    // clicked once too often would have unsubscribed themselves.
    $this->post("/fr/abonnement/{$token}")->assertOk()->assertSee('Lien expiré', escape: false);

    /** @var User $reader */
    $reader = User::query()->where('email', 'lecteur@example.test')->firstOrFail();

    expect($reader->projectWatches()->count())->toBe(1);
});

it('refuses a confirmation link whose delay has run out', function (): void {
    Notification::fake();

    $project = sheetProject();

    $this->post("/fr/abonnement/projet/{$project->slug}", ['email' => 'lecteur@example.test']);

    $link = SubscriptionLink::query()->firstOrFail();
    $link->forceFill(['expires_at' => now()->subDay()])->save();

    $this->post("/fr/abonnement/{$link->token}")->assertOk()->assertSee('Lien expiré', escape: false);

    expect(User::query()->where('email', 'lecteur@example.test')->exists())->toBeFalse();
});

it('narrows the watch to security when the box was ticked', function (): void {
    Notification::fake();

    $project = sheetProject();

    $this->post("/fr/abonnement/projet/{$project->slug}", [
        'email' => 'lecteur@example.test',
        'security' => '1',
    ]);

    $this->post('/fr/abonnement/'.SubscriptionLink::query()->firstOrFail()->token);

    /** @var User $reader */
    $reader = User::query()->where('email', 'lecteur@example.test')->firstOrFail();

    expect($reader->projectWatches()->firstOrFail()->focus_filter)->toBe(['security']);
});

it('adds the watch to the account an address already has', function (): void {
    Notification::fake();

    $project = sheetProject();
    $existing = User::factory()->create(['email' => 'connu@example.test', 'email_verified_at' => now()]);

    $this->post("/fr/abonnement/projet/{$project->slug}", ['email' => 'connu@example.test']);
    $this->post('/fr/abonnement/'.SubscriptionLink::query()->firstOrFail()->token);

    expect(User::query()->where('email', 'connu@example.test')->count())->toBe(1)
        ->and($existing->refresh()->projectWatches()->count())->toBe(1);
});

it('subscribes to an editor from its page', function (): void {
    Notification::fake();

    [$author] = Factory::contributorWithEditor();
    /** @var Editor $editor */
    $editor = $author->editors()->firstOrFail();

    $this->post("/fr/abonnement/editeur/{$editor->slug}", ['email' => 'lecteur@example.test'])
        ->assertRedirect();

    $this->post('/fr/abonnement/'.SubscriptionLink::query()->firstOrFail()->token);

    /** @var User $reader */
    $reader = User::query()->where('email', 'lecteur@example.test')->firstOrFail();

    expect($reader->editorWatches()->where('editor_id', $editor->getKey())->exists())->toBeTrue();
});

it('drops the subscription when the bait field was filled', function (): void {
    Notification::fake();

    $project = sheetProject();

    $this->post("/fr/abonnement/projet/{$project->slug}", [
        'email' => 'robot@example.test',
        'website' => 'http://spam.test',
    ])->assertRedirect();

    Notification::assertNothingSent();
    expect(SubscriptionLink::query()->count())->toBe(0);
});

it('offers the form on a project sheet to a visitor without an account', function (): void {
    $project = sheetProject();

    $this->get("/fr/projets/{$project->slug}")
        ->assertOk()
        ->assertSee('Me tenir informé', escape: false)
        ->assertDontSee('Créer un compte lecteur');
});

it('mails a preferences link and opens the page it points at', function (): void {
    Notification::fake();

    $reader = User::factory()->create(['email' => 'lecteur@example.test', 'email_verified_at' => now()]);

    $this->post('/fr/preferences', ['email' => 'lecteur@example.test'])->assertRedirect();

    Notification::assertSentTo($reader, SubscriptionManageLink::class);

    $token = (string) SubscriptionLink::query()
        ->where('purpose', SubscriptionLink::PURPOSE_MANAGE)
        ->firstOrFail()
        ->token;

    $this->get("/fr/preferences/{$token}")->assertOk()->assertSee('lecteur@example.test');

    $this->post("/fr/preferences/{$token}", [
        'email_digest' => 'weekly',
        'watches_all_security' => '1',
    ])->assertRedirect();

    expect($reader->refresh()->email_digest)->toBe(EmailDigest::WEEKLY)
        ->and($reader->watches_all_security)->toBeTrue();
});

it('says nothing about an address no account carries', function (): void {
    Notification::fake();

    $this->post('/fr/preferences', ['email' => 'inconnu@example.test'])
        ->assertRedirect(route('subscriptions.preferences.request'));

    Notification::assertNothingSent();
    expect(SubscriptionLink::query()->count())->toBe(0);
});

it('refuses a preferences page opened with an unknown token', function (): void {
    $this->get('/fr/preferences/'.str_repeat('a', 32))->assertNotFound();
});

it('refuses a preferences link whose delay has run out', function (): void {
    Notification::fake();

    User::factory()->create(['email' => 'lecteur@example.test', 'email_verified_at' => now()]);

    $this->post('/fr/preferences', ['email' => 'lecteur@example.test']);

    $link = SubscriptionLink::query()->firstOrFail();
    $link->forceFill(['expires_at' => now()->subHour()])->save();

    $this->get("/fr/preferences/{$link->token}")->assertNotFound();
});

it('drops a watch from the preferences page', function (): void {
    Notification::fake();

    $project = sheetProject();

    $this->post("/fr/abonnement/projet/{$project->slug}", ['email' => 'lecteur@example.test']);
    $this->post('/fr/abonnement/'.SubscriptionLink::query()->firstOrFail()->token);

    /** @var User $reader */
    $reader = User::query()->where('email', 'lecteur@example.test')->firstOrFail();

    $this->post('/fr/preferences', ['email' => 'lecteur@example.test']);

    $token = (string) SubscriptionLink::query()
        ->where('purpose', SubscriptionLink::PURPOSE_MANAGE)
        ->firstOrFail()
        ->token;

    $this->post("/fr/preferences/{$token}/abonnements", ['project_id' => $project->getKey()])
        ->assertRedirect();

    expect($reader->projectWatches()->count())->toBe(0);
});

it('purges the links whose delay has run out', function (): void {
    Notification::fake();

    $project = sheetProject();

    $this->post("/fr/abonnement/projet/{$project->slug}", ['email' => 'jamais@example.test']);

    SubscriptionLink::query()->firstOrFail()->forceFill(['expires_at' => now()->subDay()])->save();

    $this->artisan('dolinews:purge-subscription-links')->assertSuccessful();

    // The address of a confirmation nobody clicked goes with it: the
    // service was never allowed to write to it.
    expect(SubscriptionLink::query()->count())->toBe(0);
});

it('mints a fresh link and kills the pending one', function (): void {
    Notification::fake();

    $project = sheetProject();

    $this->post("/fr/abonnement/projet/{$project->slug}", ['email' => 'lecteur@example.test']);
    $first = (string) SubscriptionLink::query()->firstOrFail()->token;

    $this->post("/fr/abonnement/projet/{$project->slug}", ['email' => 'lecteur@example.test']);

    // Only the newest mail may be acted on: a link that leaked from an
    // old mailbox copy stops working the moment its owner asks again.
    $this->post("/fr/abonnement/{$first}")->assertOk()->assertSee('Lien expiré', escape: false);
    expect(SubscriptionLink::query()->count())->toBe(1);
});

<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Enums\ReportReason;
use App\Domain\Dolinews\Enums\ReviewDecision;
use App\Domain\Dolinews\Moderation\ReportService;
use App\Domain\Dolinews\Review\ReviewService;
use App\Models\User;
use App\Notifications\ArticleSubmitted;
use App\Notifications\ContentReported;
use App\Notifications\ReviewReminder;
use App\Notifications\ReviewThreadMessage;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Factory;

/**
 * Language targeting of the review circuit mails (SPEC 5.1/5.5/9.9).
 *
 * The rule under test has two halves and both matter: a moderator is
 * only mailed about what they declared reading, AND an entry is never
 * addressed to fewer moderators than it takes accords to publish it.
 * Dropping the second half would let an announcement in an undeclared
 * language sleep in the queue, which is the failure the filter is meant
 * to avoid, not to cause.
 */
it('leaves out a moderator who does not read the article language', function (): void {
    Notification::fake();

    $author = User::factory()->create();
    // Three Spanish readers: the fallback must not fire, so the French
    // article has to find its quorum elsewhere - and it does not.
    $spanish = User::factory()->count(3)->moderator()->create([
        'review_locales' => ['es_ES'],
    ]);
    $french = User::factory()->count(3)->moderator()->create([
        'review_locales' => ['fr_FR', 'en_US'],
    ]);

    $article = Factory::article($author, ['locale' => 'fr_FR']);
    app(ArticleService::class)->submit($article, $author);

    Notification::assertSentTo($french->all(), ArticleSubmitted::class);
    Notification::assertNotSentTo($spanish->all(), ArticleSubmitted::class);
});

it('keeps mailing a moderator who declared nothing', function (): void {
    Notification::fake();

    $author = User::factory()->create();
    $open = User::factory()->count(3)->moderator()->create();
    $greek = User::factory()->moderator()->create(['review_locales' => ['el_GR']]);

    $article = Factory::article($author, ['locale' => 'fr_FR']);
    app(ArticleService::class)->submit($article, $author);

    Notification::assertSentTo($open->all(), ArticleSubmitted::class);
    Notification::assertNotSentTo([$greek], ArticleSubmitted::class);
});

it('mails the whole team when too few moderators read the language', function (): void {
    Notification::fake();

    $author = User::factory()->create();
    // Two readers of Greek for a quorum of three: below the bar, so
    // everybody is told rather than nobody acting.
    $greek = User::factory()->count(2)->moderator()->create([
        'review_locales' => ['el_GR'],
    ]);
    $others = User::factory()->count(2)->moderator()->create([
        'review_locales' => ['fr_FR'],
    ]);

    $article = Factory::article($author, ['locale' => 'el_GR']);
    app(ArticleService::class)->submit($article, $author);

    Notification::assertSentTo($greek->all(), ArticleSubmitted::class);
    Notification::assertSentTo($others->all(), ArticleSubmitted::class);
});

it('takes one reader for a translation, not the full quorum', function (): void {
    $author = User::factory()->create();
    $source = Factory::publishedArticle($author, ['locale' => 'fr_FR']);

    Notification::fake();

    $spanish = User::factory()->moderator()->create(['review_locales' => ['es_ES']]);
    $french = User::factory()->count(3)->moderator()->create(['review_locales' => ['fr_FR']]);

    $translation = Factory::article($author, [
        'locale' => 'es_ES',
        'translation_group_id' => $source->translation_group_id,
        'is_source' => false,
        'source_revision_number' => $source->revision_number,
    ]);
    app(ArticleService::class)->submit($translation, $author);

    // A translation takes a single reviewer: one Spanish reader is
    // enough, so the fallback stays shut and the French team is spared.
    Notification::assertSentTo([$spanish], ArticleSubmitted::class);
    Notification::assertNotSentTo($french->all(), ArticleSubmitted::class);
});

it('never mails the author, moderator or not', function (): void {
    Notification::fake();

    $author = User::factory()->moderator()->create(['review_locales' => ['fr_FR']]);
    User::factory()->count(3)->moderator()->create(['review_locales' => ['fr_FR']]);

    $article = Factory::article($author, ['locale' => 'fr_FR']);
    app(ArticleService::class)->submit($article, $author);

    Notification::assertNotSentTo([$author], ArticleSubmitted::class);
});

it('keeps mailing a moderator already engaged in the thread', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author, ['locale' => 'fr_FR']);
    app(ArticleService::class)->submit($article, $author);

    $review = app(ReviewService::class);

    // A Spanish reader steps into a French thread on their own: nothing
    // stops them, the queue is open to the whole team.
    $spanish = User::factory()->moderator()->create(['review_locales' => ['es_ES']]);
    $review->postMessage($article, $spanish, 'une question sur la portee');

    Notification::fake();

    $french = User::factory()->moderator()->create(['review_locales' => ['fr_FR']]);
    $review->postMessage($article, $french, 'reponse');

    Notification::assertSentTo([$spanish], ReviewThreadMessage::class);
});

it('filters the idle reminder on the article language', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author, ['locale' => 'fr_FR']);
    app(ArticleService::class)->submit($article, $author);

    $article->forceFill(['submitted_at' => now()->subDays(10)])->save();

    Notification::fake();

    $french = User::factory()->count(3)->moderator()->create(['review_locales' => ['fr_FR']]);
    $polish = User::factory()->moderator()->create(['review_locales' => ['pl_PL']]);

    $this->artisan('dolinews:review-reminders')->assertSuccessful();

    Notification::assertSentTo($french->all(), ReviewReminder::class);
    Notification::assertNotSentTo([$polish], ReviewReminder::class);
});

it('filters a content report on the reporter language', function (): void {
    $author = User::factory()->create();
    $article = Factory::publishedArticle($author);

    Notification::fake();

    $spanish = User::factory()->moderator()->create(['review_locales' => ['es_ES']]);
    $french = User::factory()->moderator()->create(['review_locales' => ['fr_FR']]);

    app(ReportService::class)->reportArticle($article, [
        'reason' => ReportReason::SPAM,
        'body' => 'Este anuncio no describe ningun modulo.',
        'email' => 'lector@exemple.test',
        'locale' => 'es',
    ]);

    // A report carries the reporter's own text: someone has to read it,
    // and one addressee is enough.
    Notification::assertSentTo([$spanish], ContentReported::class);
    Notification::assertNotSentTo([$french], ContentReported::class);
});

it('reads a declared language whatever shape the locale takes', function (): void {
    $moderator = User::factory()->moderator()->create(['review_locales' => ['es_ES']]);

    expect($moderator->readsContentLocale('es_ES'))->toBeTrue()
        ->and($moderator->readsContentLocale('es'))->toBeTrue()
        ->and($moderator->readsContentLocale('es-MX'))->toBeTrue()
        ->and($moderator->readsContentLocale('fr_FR'))->toBeFalse();
});

it('reads everything on an emptied selection', function (): void {
    $moderator = User::factory()->moderator()->create(['review_locales' => []]);

    expect($moderator->readsContentLocale('el_GR'))->toBeTrue();
});

it('writes the submission mail in Spanish when Spanish is asked for', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author, ['locale' => 'fr_FR']);
    $moderator = User::factory()->moderator()->create(['locale' => 'es']);

    App::setLocale('es');
    $mail = (new ArticleSubmitted($article, $author))->toMail($moderator);
    App::setLocale('fr');

    expect($mail->subject)->toContain('Enviado a revisión')
        ->and($mail->introLines)->toContain('Un artículo acaba de entrar en la cola de revisión.');
});

it('sends the circuit mail in the language of its recipient', function (): void {
    $author = User::factory()->create();
    // The Spanish-reading moderator gets the French announcement AND a
    // mail written in Spanish: filtering the addressee without
    // translating the notice would leave the same wall in place.
    User::factory()->moderator()->create([
        'locale' => 'es',
        'review_locales' => ['fr_FR'],
    ]);

    $article = Factory::article($author, ['locale' => 'fr_FR']);
    app(ArticleService::class)->submit($article, $author);

    // The array transport keeps what actually went out, subject
    // included: this asserts the locale the SENDER applied, not the one
    // the test asked for.
    $subjects = collect(Mail::getSymfonyTransport()->messages())
        ->map(fn ($sent): string => (string) $sent->getOriginalMessage()->getSubject())
        ->all();

    expect($subjects)->toContain('[DoliNews] Enviado a revisión: Module XY 2.1');
});

it('accepts a review language selection from a moderator only', function (): void {
    $moderator = User::factory()->moderator()->create(['email_verified_at' => now()]);

    $this->actingAs($moderator)
        ->post(route('account.review-locales'), ['review_locales' => ['es_ES', 'fr_FR']])
        ->assertRedirect();

    expect($moderator->refresh()->review_locales)->toBe(['es_ES', 'fr_FR']);

    // Unchecking everything is a reset, never a resignation.
    $this->actingAs($moderator)
        ->post(route('account.review-locales'), [])
        ->assertRedirect();

    expect($moderator->refresh()->review_locales)->toBeNull();

    $reader = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($reader)
        ->post(route('account.review-locales'), ['review_locales' => ['es_ES']])
        ->assertForbidden();
});

it('offers the selection to a moderator and to nobody else', function (): void {
    $moderator = User::factory()->moderator()->create(['email_verified_at' => now()]);

    $this->actingAs($moderator)
        ->get(route('account.show'))
        ->assertOk()
        ->assertSee('Langues que vous relisez');

    $reader = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($reader)
        ->get(route('account.show'))
        ->assertOk()
        ->assertDontSee('Langues que vous relisez');
});

it('refuses a locale the service does not carry', function (): void {
    $moderator = User::factory()->moderator()->create(['email_verified_at' => now()]);

    $this->actingAs($moderator)
        ->post(route('account.review-locales'), ['review_locales' => ['xx_XX']])
        ->assertSessionHasErrors('review_locales.0');
});

it('accepts an accord from a moderator outside their declared languages', function (): void {
    // The filter targets mails, never rights: the queue stays open.
    $author = User::factory()->create();
    $article = Factory::article($author, ['locale' => 'el_GR']);
    app(ArticleService::class)->submit($article, $author);

    $review = app(ReviewService::class);

    foreach (User::factory()->count(3)->moderator()->create(['review_locales' => ['fr_FR']]) as $moderator) {
        $review->postMessage($article, $moderator, 'accord', ReviewDecision::ACCEPTED);
    }

    expect($article->refresh()->status->value)->toBe('published');
});

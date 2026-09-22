<?php

declare(strict_types=1);

use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\ReportReason;
use App\Domain\Dolinews\Enums\ReportStatus;
use App\Domain\Dolinews\Models\ContentReport;
use App\Domain\Dolinews\Models\ModerationLog;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Moderation\ReportException;
use App\Domain\Dolinews\Moderation\ReportService;
use App\Livewire\Admin\ReportList;
use App\Models\User;
use App\Notifications\ContentReported;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * Reporting a published content to the moderation team (SPEC 9.9).
 *
 * The review happens before publication (D6), which does not make it
 * infallible: what is validated too fast stays online until a reader
 * says so, and that reader has no account and reads no French.
 */
it('opens the report form on a published article, without an account', function (): void {
    $article = Factory::publishedArticle(User::factory()->create(), ['title' => 'Module XY 2.1 stable']);

    $this->get(route('reports.article', $article))
        ->assertOk()
        ->assertSee('Signaler un contenu')
        ->assertSee('Module XY 2.1 stable');
});

it('opens the report form on a project sheet', function (): void {
    [$author] = Factory::contributorWithEditor();
    $project = Project::query()->create([
        'editor_id' => Factory::editorFor($author)->getKey(),
        'slug' => 'module-xy',
        'name' => 'Module XY',
        'summary' => 'Un module de test.',
        'status' => 'active',
    ]);

    $this->get(route('reports.project', $project->slug))
        ->assertOk()
        ->assertSee('Module XY');
});

it('offers the report link on the article and on the sheet', function (): void {
    [$author] = Factory::contributorWithEditor();
    $article = Factory::publishedArticle($author);

    $this->get(route('articles.show', $article))
        ->assertOk()
        ->assertSee(route('reports.article', $article));

    $project = Project::query()->create([
        'editor_id' => Factory::editorFor($author)->getKey(),
        'slug' => 'module-signale',
        'name' => 'Module signale',
        'summary' => 'Un module de test.',
        'status' => 'active',
    ]);

    $this->get(route('projects.show', $project->slug))
        ->assertOk()
        ->assertSee(route('reports.project', $project->slug));
});

/**
 * Nothing that is not public is reportable: a draft or an article still
 * in review has no reader to report it, and a withdrawn one is already
 * gone.
 */
it('refuses to report what is not published', function (): void {
    $author = User::factory()->create();
    $draft = Factory::article($author);

    $this->get(route('reports.article', $draft))->assertNotFound();

    $this->post(route('reports.article.store', $draft), [
        'reason' => ReportReason::SPAM->value,
        'body' => 'Ce contenu n\'a rien a faire ici du tout.',
        'email' => 'lecteur@example.test',
    ])->assertNotFound();
});

it('records a report and mails the review team', function (): void {
    Notification::fake();

    $author = User::factory()->create();
    $article = Factory::publishedArticle($author);
    $moderator = User::factory()->moderator()->create();
    $reader = User::factory()->create();

    $this->post(route('reports.article.store', $article), [
        'reason' => ReportReason::ILLEGAL->value,
        'body' => 'Cette annonce reprend mot pour mot la documentation d\'un tiers.',
        'email' => 'lector@example.test',
    ])->assertRedirect(route('reports.article', $article));

    $report = ContentReport::query()->firstOrFail();

    expect($report->article_id)->toBe($article->getKey())
        ->and($report->reason)->toBe(ReportReason::ILLEGAL)
        ->and($report->status)->toBe(ReportStatus::OPEN)
        ->and($report->reporter_email)->toBe('lector@example.test')
        ->and($report->reporter_user_id)->toBeNull();

    Notification::assertSentTo($moderator, ContentReported::class);
    // A plain reader is not on the team and hears nothing about it.
    Notification::assertNotSentTo($reader, ContentReported::class);
});

/**
 * One article must not become a mail bomb aimed at the team: only the
 * first open report of a target mails, the others pile up in the queue.
 */
it('mails the team once per target while a report stays open', function (): void {
    Notification::fake();

    $article = Factory::publishedArticle(User::factory()->create());
    $moderator = User::factory()->moderator()->create();

    foreach (['un@example.test', 'deux@example.test', 'trois@example.test'] as $address) {
        $this->post(route('reports.article.store', $article), [
            'reason' => ReportReason::SPAM->value,
            'body' => 'Annonce sans rapport avec le module annonce ici.',
            'email' => $address,
        ])->assertRedirect();
    }

    expect(ContentReport::query()->count())->toBe(3);

    Notification::assertSentToTimes($moderator, ContentReported::class, 1);
});

/**
 * Once the queue is cleared, the target can warn again: the silence was
 * a deduplication, not a mute.
 */
it('mails again after the open report was handled', function (): void {
    Notification::fake();

    $article = Factory::publishedArticle(User::factory()->create());
    $moderator = User::factory()->moderator()->create();
    $reports = app(ReportService::class);

    $first = $reports->reportArticle($article, [
        'reason' => ReportReason::SPAM,
        'body' => 'Premier signalement.',
        'email' => 'un@example.test',
        'locale' => 'es',
    ]);

    $reports->dismiss($first, $moderator, 'Rien de contraire aux regles.');

    $reports->reportArticle($article, [
        'reason' => ReportReason::SPAM,
        'body' => 'Second signalement.',
        'email' => 'deux@example.test',
        'locale' => 'es',
    ]);

    Notification::assertSentToTimes($moderator, ContentReported::class, 2);
});

/**
 * The reporter writes in their own language, and the team reads which
 * one before opening the body: the answer goes out in it.
 */
it('keeps the interface locale of the reporter', function (): void {
    Notification::fake();

    $article = Factory::publishedArticle(User::factory()->create());

    $this->get(route('locale.switch', ['locale' => 'es']));

    $this->post(route('reports.article.store', $article), [
        'reason' => ReportReason::MISLEADING_LINK->value,
        'body' => 'El enlace lleva a otra pagina totalmente distinta.',
        'email' => 'lector@example.test',
    ])->assertRedirect();

    expect(ContentReport::query()->firstOrFail()->locale)->toBe('es');
});

it('drops a submission that filled the bait field, without saying so', function (): void {
    Notification::fake();

    $article = Factory::publishedArticle(User::factory()->create());
    $moderator = User::factory()->moderator()->create();

    $this->post(route('reports.article.store', $article), [
        'reason' => ReportReason::SPAM->value,
        'body' => 'Message depose par un robot de formulaire.',
        'email' => 'robot@example.test',
        'website' => 'https://exemple.test',
    ])->assertRedirect(route('reports.article', $article));

    expect(ContentReport::query()->count())->toBe(0);
    Notification::assertNotSentTo($moderator, ContentReported::class);
});

it('requires a reason, a description and a contact address', function (): void {
    $article = Factory::publishedArticle(User::factory()->create());

    $this->post(route('reports.article.store', $article), [
        'reason' => '',
        'body' => 'trop court',
        'email' => 'pas-une-adresse',
    ])->assertSessionHasErrors(['reason', 'body', 'email']);

    expect(ContentReport::query()->count())->toBe(0);
});

/**
 * The channel is open without an account, so it is bounded by origin:
 * a form nobody can flood, and a read nobody is throttled out of.
 */
it('bounds the reports one origin may file per hour', function (): void {
    Notification::fake();

    config()->set('dolinews.reports.per_hour_ip', 2);

    $article = Factory::publishedArticle(User::factory()->create());

    foreach (range(1, 2) as $index) {
        $this->post(route('reports.article.store', $article), [
            'reason' => ReportReason::SPAM->value,
            'body' => 'Signalement numero '.$index.' de cette origine.',
            'email' => 'lecteur@example.test',
        ])->assertRedirect();
    }

    $this->post(route('reports.article.store', $article), [
        'reason' => ReportReason::SPAM->value,
        'body' => 'Signalement numero trois de cette origine.',
        'email' => 'lecteur@example.test',
    ])->assertStatus(429);

    expect(ContentReport::query()->count())->toBe(2);
});

/**
 * Handling a report from the queue is the same moderation act as
 * anywhere else: it lands in moderation_log with its numbered rule and
 * its motive (SPEC 9.4).
 */
it('hides the reported article and closes the report from the queue', function (): void {
    Notification::fake();

    $article = Factory::publishedArticle(User::factory()->create());
    $moderator = User::factory()->moderator()->create();

    $report = app(ReportService::class)->reportArticle($article, [
        'reason' => ReportReason::ILLEGAL,
        'body' => 'Contenu manifestement illicite.',
        'email' => 'lecteur@example.test',
        'locale' => 'fr',
    ]);

    Livewire::actingAs($moderator)
        ->test(ReportList::class)
        ->call('openAct', $report->getKey())
        ->set('actKind', 'hide')
        ->set('actRule', 'R3')
        ->set('actMotive', 'Contenu illicite, retire le temps de la contestation.')
        ->call('applyAct')
        ->assertHasNoErrors();

    expect($article->refresh()->status)->toBe(ArticleStatus::HIDDEN)
        ->and($report->refresh()->status)->toBe(ReportStatus::ACTIONED)
        ->and($report->handled_by_user_id)->toBe($moderator->getKey());

    $log = ModerationLog::query()->where('article_id', $article->getKey())->firstOrFail();

    expect($log->rule_ref)->toBe('R3');
});

it('dismisses a report without touching the article', function (): void {
    Notification::fake();

    $article = Factory::publishedArticle(User::factory()->create());
    $moderator = User::factory()->moderator()->create();

    $report = app(ReportService::class)->reportArticle($article, [
        'reason' => ReportReason::OTHER,
        'body' => 'Je n\'aime pas ce module.',
        'email' => 'lecteur@example.test',
        'locale' => 'fr',
    ]);

    Livewire::actingAs($moderator)
        ->test(ReportList::class)
        ->call('openAct', $report->getKey())
        ->set('actKind', 'dismiss')
        ->set('actMotive', 'Aucun manquement aux regles d\'utilisation.')
        ->call('applyAct')
        ->assertHasNoErrors();

    expect($article->refresh()->status)->toBe(ArticleStatus::PUBLISHED)
        ->and($report->refresh()->status)->toBe(ReportStatus::DISMISSED)
        ->and(ModerationLog::query()->count())->toBe(0);
});

it('refuses to close a report twice', function (): void {
    Notification::fake();

    $article = Factory::publishedArticle(User::factory()->create());
    $moderator = User::factory()->moderator()->create();
    $reports = app(ReportService::class);

    $report = $reports->reportArticle($article, [
        'reason' => ReportReason::SPAM,
        'body' => 'Signalement unique.',
        'email' => 'lecteur@example.test',
        'locale' => 'fr',
    ]);

    $reports->dismiss($report, $moderator, 'Rien a signaler.');

    expect(fn () => $reports->dismiss($report->refresh(), $moderator, 'Encore rien.'))
        ->toThrow(ReportException::class);
});

it('keeps the reports queue out of reach of a reader', function (): void {
    $reader = User::factory()->create();

    $this->actingAs($reader)->get(route('admin.reports'))->assertForbidden();
});

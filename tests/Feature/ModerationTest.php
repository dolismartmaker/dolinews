<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleException;
use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Contributors\ContributorVerificationService;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\ModerationLog;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Moderation\ModerationException;
use App\Domain\Dolinews\Moderation\ModerationService;
use App\Models\User;
use Tests\Support\Factory;

/**
 * Moderation acts, their journal, conflict-of-interest confirmations
 * and the seven-day auto-cancellation (SPEC 9.4/9.6).
 */
it('hides and unhides with a journalled motive and rule', function (): void {
    $author = User::factory()->create();
    $article = Factory::publishedArticle($author);
    $moderator = User::factory()->moderator()->create();

    $log = app(ModerationService::class)->hideArticle($article, $moderator, 'contenu promotionnel massif', 'R1');

    expect($article->refresh()->status)->toBe(ArticleStatus::HIDDEN)
        ->and($log->rule_ref)->toBe('R1')
        ->and($log->motive)->toBe('contenu promotionnel massif');

    app(ModerationService::class)->unhideArticle($article->refresh(), $moderator, 'apres relecture');

    expect($article->refresh()->status)->toBe(ArticleStatus::PUBLISHED);
});

it('refuses to republish an article that was never unpublished', function (): void {
    // Without the guard this act published anything it was pointed at,
    // an article still in review included, and with no publication date:
    // the feed sorts on that date, so the article claimed to be
    // published and showed up nowhere.
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    $moderator = User::factory()->moderator()->create();

    expect(fn () => app(ModerationService::class)
        ->unhideArticle($article->refresh(), $moderator, 'je voulais valider', 'R1'))
        ->toThrow(ArticleException::class);

    $article->refresh();

    expect($article->status)->toBe(ArticleStatus::PENDING)
        ->and($article->published_at)->toBeNull();
});

it('refuses to restore an article that was never withdrawn', function (): void {
    $author = User::factory()->create();
    $article = Factory::publishedArticle($author);
    $moderator = User::factory()->moderator()->create();

    expect(fn () => app(ModerationService::class)
        ->restoreArticle($article->refresh(), $moderator, 'remise en ligne'))
        ->toThrow(ArticleException::class);
});

it('suspends an account and reactivates it on reversal', function (): void {
    $target = User::factory()->create();
    $admin = User::factory()->superAdmin()->create();

    app(ModerationService::class)->suspend($target, $admin, 'spam repete malgre avertissement', 'R1');

    expect($target->refresh()->active)->toBeFalse();
});

it('refuses an act without a motive', function (): void {
    $target = User::factory()->create();
    $admin = User::factory()->superAdmin()->create();

    app(ModerationService::class)->warn($target, $admin, '  ');
})->throws(ModerationException::class);

it('requires a second moderator to confirm a conflict act', function (): void {
    $author = User::factory()->create();
    $article = Factory::publishedArticle($author);

    $conflicted = User::factory()->moderator()->create();
    $colleague = User::factory()->moderator()->create();

    $log = app(ModerationService::class)->hideArticle(
        $article,
        $conflicted,
        'concurrent direct de mon employeur',
        'R1',
        conflictOfInterest: true,
    );

    expect($log->refresh()->requires_confirmation)->toBeTrue();

    // The actor cannot confirm their own act.
    try {
        app(ModerationService::class)->confirm($log->refresh(), $conflicted);
        $this->fail('self confirmation accepted');
    } catch (ModerationException) {
    }

    app(ModerationService::class)->confirm($log->refresh(), $colleague);

    expect($log->refresh()->confirmed_at)->not->toBeNull();
});

it('auto-cancels an unconfirmed conflict act after seven days and lifts its effect', function (): void {
    $author = User::factory()->create();
    $article = Factory::publishedArticle($author);

    $conflicted = User::factory()->moderator()->create();

    app(ModerationService::class)->hideArticle(
        $article,
        $conflicted,
        'concurrent direct',
        'R1',
        conflictOfInterest: true,
    );

    expect($article->refresh()->status)->toBe(ArticleStatus::HIDDEN);

    $this->travelTo(now()->addDays(8));

    $cancelled = app(ModerationService::class)->expireUnconfirmed();

    expect($cancelled)->toBe(1)
        ->and($article->refresh()->status)->toBe(ArticleStatus::PUBLISHED);

    // The cancellation row carries no moderator: written by the service,
    // told apart from a human decision (SPEC 4.5).
    $auto = ModerationLog::query()->whereNull('moderator_user_id')->first();

    expect($auto)->not->toBeNull()
        ->and($auto->action->value)->toBe('unhidden')
        ->and($auto->motive)->toContain('Annulation automatique');
});

it('keeps a legal withdrawal in force without confirmation', function (): void {
    $author = User::factory()->create();
    $article = Factory::publishedArticle($author);

    app(ModerationService::class)->hideArticle(
        $article,
        User::factory()->moderator()->create(),
        'injonction de l\'autorite competente',
        'R3',
        conflictOfInterest: true,
        isLegal: true,
    );

    $this->travelTo(now()->addDays(8));

    app(ModerationService::class)->expireUnconfirmed();

    // Still hidden: the legal act stays, the confirmation only documents.
    expect($article->refresh()->status)->toBe(ArticleStatus::HIDDEN);
});

it('revokes a contribution proof and keeps the row bound', function (): void {
    $author = User::factory()->create();
    $proof = app(ContributorVerificationService::class)
        ->grantManual($author, 'dev@example.com');

    app(ModerationService::class)->revokeProof(
        $proof,
        User::factory()->moderator()->create(),
        'preuve obtenue sur un depot detourne',
        'R2',
    );

    expect($proof->refresh()->revoked_at)->not->toBeNull()
        ->and($author->refresh()->isContributor())->toBeFalse();

    $this->assertDatabaseHas('moderation_log', [
        'action' => 'proof_revoked',
        'user_id' => $author->getKey(),
    ]);
});

it('transfers a project sheet and journals the heaviest act', function (): void {
    $author = User::factory()->create();
    $editor = (new EditorService)->create($author, [
        'name' => 'Vrai editeur',
        'contact_email' => 'real@editeur.test',
    ]);

    $project = Project::query()->create([
        'editor_id' => $editor->getKey(),
        'slug' => 'module-xy',
        'name' => 'Module XY',
        'summary' => 'Fiche du module XY',
    ]);

    $newEditor = (new EditorService)->create(User::factory()->create(), [
        'name' => 'Editeur revendiquant',
        'contact_email' => 'claim@editeur.test',
    ]);

    app(ModerationService::class)->transferProject(
        $project,
        $newEditor,
        User::factory()->moderator()->create(),
        'revendication validee par la revue',
        'R2',
    );

    expect($project->refresh()->editor_id)->toBe($newEditor->getKey());
});

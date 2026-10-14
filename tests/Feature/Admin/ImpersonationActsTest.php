<?php

declare(strict_types=1);

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Articles\RevisionService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\ReportReason;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\ModerationLog;
use App\Domain\Dolinews\Moderation\ReportService;
use App\Livewire\Admin\ArticleList;
use App\Livewire\Admin\EditorList;
use App\Livewire\Admin\ModerationLogList;
use App\Livewire\Admin\ReportList;
use App\Livewire\Admin\ReviewShow;
use App\Livewire\Admin\UserList;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * A super admin switched to a moderator sees the back-office as they do,
 * and acts in nothing: an accord would count in the quorum, a
 * confirmation would have the operator confirm itself (SPEC 9.1, 9.6).
 */
function switchToModerator(): User
{
    $admin = User::factory()->superAdmin()->create();
    $moderator = User::factory()->moderator()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('switchTo', $moderator->getKey());

    test()->assertAuthenticatedAs($moderator);

    return $moderator;
}

it('shows the review screen but refuses a decision', function (): void {
    $author = User::factory()->create();
    $article = Factory::article($author);
    app(ArticleService::class)->submit($article, $author);

    switchToModerator();

    Livewire::test(ReviewShow::class, ['article' => $article])
        ->assertOk()
        ->set('messageBody', 'relecture faite, rien a redire')
        ->call('decide', 'accepted')
        ->assertHasErrors('messageBody')
        ->assertNoRedirect();

    expect($article->reviewMessages()->count())->toBe(0);
});

it('refuses to apply a revision', function (): void {
    $author = User::factory()->create();
    $article = Factory::publishedArticle($author);
    $revision = app(RevisionService::class)->propose($article, $author, ['summary' => 'Resume corrige.'], 'coquille');

    switchToModerator();

    Livewire::test(ReviewShow::class, ['article' => $article])
        ->call('applyRevision', $revision->getKey())
        ->assertHasErrors('revision');

    expect($revision->fresh()?->status)->toBe('pending');
});

it('refuses to hide an article', function (): void {
    $article = Factory::publishedArticle(User::factory()->create());

    switchToModerator();

    Livewire::test(ArticleList::class)
        ->call('openAct', $article->getKey())
        ->set('actKind', 'hide')
        ->set('actRule', 'R4')
        ->set('actMotive', 'Lien trompeur vers une destination sans rapport.')
        ->call('applyAct');

    expect($article->fresh()?->status)->toBe(ArticleStatus::PUBLISHED)
        ->and(ModerationLog::query()->count())->toBe(0);
});

it('refuses to act on a report', function (): void {
    Notification::fake();

    $article = Factory::publishedArticle(User::factory()->create());
    $report = app(ReportService::class)->reportArticle($article, [
        'reason' => ReportReason::ILLEGAL,
        'body' => 'Contenu manifestement illicite.',
        'email' => 'lecteur@example.test',
        'locale' => 'fr',
    ]);

    switchToModerator();

    Livewire::test(ReportList::class)
        ->call('openAct', $report->getKey())
        ->set('actKind', 'hide')
        ->set('actRule', 'R3')
        ->set('actMotive', 'Contenu illicite, retire le temps de la contestation.')
        ->call('applyAct');

    expect($article->fresh()?->status)->toBe(ArticleStatus::PUBLISHED)
        ->and($report->fresh()?->status->value)->toBe('open');
});

it('refuses to confirm an act as the second moderator', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $target = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('openAct', $target->getKey())
        ->set('actRule', 'R1')
        ->set('actMotive', 'Soumissions répétées sans rapport avec Dolibarr.')
        ->set('actConflict', true)
        ->call('suspend')
        ->assertHasNoErrors();

    $log = ModerationLog::query()->where('requires_confirmation', true)->firstOrFail();

    auth()->logout();
    switchToModerator();

    Livewire::test(ModerationLogList::class)->call('confirm', $log->getKey());

    expect($log->fresh()?->confirmed_at)->toBeNull();
});

it('refuses to validate an editor', function (): void {
    $editor = Factory::editorFor(User::factory()->create());
    $editor->verified_at = null;
    $editor->save();

    switchToModerator();

    Livewire::test(EditorList::class)->call('validateEditor', $editor->getKey());

    expect(Editor::query()->find($editor->getKey())?->verified_at)->toBeNull();
});

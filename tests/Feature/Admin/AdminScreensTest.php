<?php

declare(strict_types=1);

use App\Domain\Dolinews\Enums\ModerationAction;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\ModerationLog;
use App\Livewire\Admin\ApiRequestList;
use App\Livewire\Admin\ArticleList;
use App\Livewire\Admin\EditorList;
use App\Livewire\Admin\MediaList;
use App\Livewire\Admin\ModerationLogList;
use App\Livewire\Admin\ProjectList;
use App\Livewire\Admin\UserList;
use App\Models\User;
use Livewire\Drawer\Utils;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * Back-office screens: what the row actions are wired to, and what the act
 * panels do once opened.
 */
it('wires every row action to a method Livewire will accept', function (string $component): void {
    // Livewire only calls methods the subclass declares itself: an action
    // pointing at an inherited method (validate(), mount(), render()) raises
    // MethodNotFoundException on click, which no page test would notice.
    $this->actingAs(User::factory()->superAdmin()->create());

    $instance = app($component);
    $callable = Utils::getPublicMethodsDefinedBySubClass($instance);
    $actions = $instance->actions();

    expect($actions)->toBeArray();

    foreach ($actions as $action) {
        expect($callable)->toContain($action['method']);
    }
})->with([
    UserList::class,
    EditorList::class,
    ArticleList::class,
    ModerationLogList::class,
    ProjectList::class,
    MediaList::class,
    ApiRequestList::class,
]);

it('names every list screen', function (string $component): void {
    // Six tables with no heading is how a back-office becomes unreadable.
    expect(app($component)->heading())->not->toBe('');
})->with([
    UserList::class,
    EditorList::class,
    ArticleList::class,
    ModerationLogList::class,
    ProjectList::class,
    MediaList::class,
    ApiRequestList::class,
]);

it('opens the account act panel and suspends with a rule and a motive', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $target = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('openAct', $target->getKey())
        // The panel has to find its target, otherwise the button changes
        // state and nothing appears on screen.
        ->assertSee($target->email)
        ->set('actRule', 'R1')
        ->set('actMotive', 'Soumissions répétées sans rapport avec Dolibarr.')
        ->call('suspend')
        ->assertHasNoErrors();

    expect($target->fresh()?->active)->toBeFalse();

    // A sanction with no numbered rule is not enforceable (SPEC 9.2).
    expect(ModerationLog::query()->where('action', ModerationAction::SUSPENDED->value)->value('rule_ref'))->toBe('R1');
});

it('refuses a suspension without a rule', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $target = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('openAct', $target->getKey())
        ->set('actMotive', 'Motif suffisamment long pour passer la règle de longueur.')
        ->call('suspend')
        ->assertHasErrors('actRule');

    expect($target->fresh()?->active)->toBeTrue();
});

it('closes the act panel without acting', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $target = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('openAct', $target->getKey())
        ->call('closeAct')
        ->assertSet('actUserId', null);

    expect($target->fresh()?->active)->toBeTrue();
});

it('hides an article from its act panel', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $author = User::factory()->create();
    $article = Factory::publishedArticle($author);

    Livewire::actingAs($admin)
        ->test(ArticleList::class)
        ->call('openAct', $article->getKey())
        ->assertSee($article->title)
        ->set('actKind', 'hide')
        ->set('actRule', 'R4')
        ->set('actMotive', 'Lien trompeur vers une destination sans rapport.')
        ->call('applyAct')
        ->assertHasNoErrors();

    expect($article->fresh()?->status->value)->toBe('hidden');
});

it('validates an editor from the row action', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $author = User::factory()->create();
    $editor = Factory::editorFor($author);
    $editor->verified_at = null;
    $editor->save();

    Livewire::actingAs($admin)
        ->test(EditorList::class)
        ->call('validateEditor', $editor->getKey());

    expect(Editor::query()->find($editor->getKey())?->verified_at)->not->toBeNull();
});

it('confirms a conflict-of-interest act from the moderation journal', function (): void {
    // An act taken in a conflict of interest is cancelled by default unless a
    // second moderator confirms it within seven days (SPEC 9.6), so the button
    // that confirms it is the one that must not be broken.
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

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(ModerationLogList::class)
        ->call('confirm', $log->getKey());

    expect($log->fresh()?->confirmed_at)->not->toBeNull();
});

it('spells out the boolean and enum columns of the lists', function (): void {
    // A raw cast prints "1", an empty cell or a quoted JSON value; a list is
    // read, not decoded.
    $admin = User::factory()->superAdmin()->create();
    $author = User::factory()->create();
    Factory::publishedArticle($author, ['title' => 'Module ZZ 1.0']);

    Livewire::actingAs($admin)->test(UserList::class)->assertSee('oui');
    Livewire::actingAs($admin)->test(ArticleList::class)->assertSee('publié');
});

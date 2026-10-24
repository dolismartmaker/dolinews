<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditEntry;
use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Articles\PublicationQuotaService;
use App\Domain\Dolinews\Articles\QuotaException;
use App\Domain\Dolinews\Editors\EditorException;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Livewire\Admin\EditorList;
use App\Models\User;
use Livewire\Livewire;

/**
 * Publication quota raised for one editor until a date (SPEC 5.3), by
 * the super admin, never on an editor the super admin belongs to
 * (SPEC 9.6). The common values come back by themselves at the end
 * date, and the tokens spent meanwhile leave no debt behind.
 */

/**
 * An editor with its owner, and a draft announcement factory for it.
 *
 * @return array{0: User, 1: Editor}
 */
function raisedEditor(): array
{
    $author = User::factory()->create();
    $editor = (new EditorService)->create($author, [
        'name' => 'Association test',
        'contact_email' => 'asso@editeur.test',
    ]);

    return [$author, $editor];
}

function raisedDraft(User $author, Editor $editor): Article
{
    return app(ArticleService::class)->createDraft($author, $editor, [
        'type' => 'announcement',
        'title' => 'Compte rendu '.uniqid(),
        'summary' => 'Resume',
        'body' => 'Corps',
        'locale' => 'en_US',
        'maturity' => 'stable',
        'compat_status' => 'declared',
    ]);
}

it('lets an editor submit past the common values while its raise applies', function (): void {
    [$author, $editor] = raisedEditor();
    $admin = User::factory()->superAdmin()->create();

    app(EditorService::class)->raiseQuota($editor, $admin, 10, 10, now()->addDays(10), 'Dépôt des archives');

    for ($i = 0; $i < 8; $i++) {
        $article = raisedDraft($author, $editor);
        app(ArticleService::class)->submit($article, $author);
        expect($article->refresh()->status)->toBe(ArticleStatus::PENDING);
    }
});

it('still refuses past the raised values', function (): void {
    [$author, $editor] = raisedEditor();
    $admin = User::factory()->superAdmin()->create();

    app(EditorService::class)->raiseQuota($editor, $admin, 4, 10, now()->addDays(10), 'Dépôt des archives');

    for ($i = 0; $i < 4; $i++) {
        app(ArticleService::class)->submit(raisedDraft($author, $editor), $author);
    }

    app(ArticleService::class)->submit(raisedDraft($author, $editor), $author);
})->throws(QuotaException::class, 'plafond 4');

it('brings the common values back at the end date, without debt', function (): void {
    [$author, $editor] = raisedEditor();
    $admin = User::factory()->superAdmin()->create();
    $quota = app(PublicationQuotaService::class);

    app(EditorService::class)->raiseQuota($editor, $admin, 10, 10, now()->addDay(), 'Dépôt des archives');

    for ($i = 0; $i < 6; $i++) {
        app(ArticleService::class)->submit(raisedDraft($author, $editor), $author);
    }

    $this->travel(3)->days();
    $editor->refresh();

    // Six tokens spent under a capacity of ten: the bucket falls back to
    // the common capacity, not below zero.
    expect($quota->capacityFor($editor))->toBe(3)
        ->and($quota->queueCeilingFor($editor))->toBe(5)
        ->and($quota->availableTokensFor(raisedDraft($author, $editor)))->toBe(3);
});

it('ends a raise now, keeping what was spent from turning into debt', function (): void {
    [$author, $editor] = raisedEditor();
    $admin = User::factory()->superAdmin()->create();
    $quota = app(PublicationQuotaService::class);

    app(EditorService::class)->raiseQuota($editor, $admin, 10, null, now()->addDays(30), 'Dépôt des archives');

    for ($i = 0; $i < 5; $i++) {
        app(ArticleService::class)->submit(raisedDraft($author, $editor), $author);
    }

    $this->travel(1)->minutes();
    app(EditorService::class)->endQuotaRaise($editor, $admin);
    $editor->refresh();

    expect($editor->quotaOverrideAppliesAt(now()))->toBeFalse()
        ->and($quota->capacityFor($editor))->toBe(3)
        ->and($quota->availableTokensFor(raisedDraft($author, $editor)))->toBe(3);
});

it('refuses to raise the quota of an editor the super admin belongs to', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $editor = (new EditorService)->create($admin, [
        'name' => 'Editeur de l\'exploitant',
        'contact_email' => 'exploitant@editeur.test',
    ]);

    app(EditorService::class)->raiseQuota($editor, $admin, 10, 10, now()->addDays(10), 'Pour moi');
})->throws(EditorException::class, 'membre de cet éditeur');

it('requires an end date within bounds, a value and a motive', function (array $case, string $message): void {
    [, $editor] = raisedEditor();
    $admin = User::factory()->superAdmin()->create();

    expect(fn () => app(EditorService::class)->raiseQuota(
        $editor,
        $admin,
        $case['capacity'],
        $case['ceiling'],
        $case['until'](),
        $case['motive'],
    ))->toThrow(EditorException::class, $message);

    expect($editor->refresh()->quota_override_until)->toBeNull();
})->with([
    'past end date' => [['capacity' => 10, 'ceiling' => null, 'until' => fn () => now()->subDay(), 'motive' => 'x'], 'à venir'],
    'end date too far' => [['capacity' => 10, 'ceiling' => null, 'until' => fn () => now()->addDays(EditorService::QUOTA_RAISE_MAX_DAYS + 2), 'motive' => 'x'], 'au plus'],
    'nothing raised' => [['capacity' => null, 'ceiling' => null, 'until' => fn () => now()->addDays(5), 'motive' => 'x'], 'au moins une valeur'],
    'no motive' => [['capacity' => 10, 'ceiling' => null, 'until' => fn () => now()->addDays(5), 'motive' => '  '], 'motif'],
]);

it('raises the quota from the back-office and journals the values it replaces', function (): void {
    [, $editor] = raisedEditor();
    $admin = User::factory()->superAdmin()->create();
    $until = now()->addDays(20)->format('Y-m-d');

    Livewire::actingAs($admin)
        ->test(EditorList::class)
        ->call('openEditor', $editor->getKey())
        ->set('editorQuotaCapacity', '20')
        ->set('editorQuotaCeiling', '20')
        ->set('editorQuotaUntil', $until)
        ->set('editorQuotaMotive', 'Dépôt des comptes rendus de 2026')
        ->call('saveQuotaRaise')
        ->assertHasNoErrors()
        ->assertSee('Relevé jusqu\'au');

    $editor->refresh();
    $quota = app(PublicationQuotaService::class);

    expect($quota->capacityFor($editor))->toBe(20)
        ->and($quota->queueCeilingFor($editor))->toBe(20)
        ->and($editor->quota_override_until?->format('Y-m-d'))->toBe($until);

    $entry = AuditEntry::query()->where('action', 'editor.quota_raised')->sole();

    expect($entry->meta['previous'])->toBe(['capacity' => null, 'queue_ceiling' => null, 'until' => null])
        ->and($entry->meta['current']['capacity'])->toBe(20)
        ->and($entry->meta['motive'])->toBe('Dépôt des comptes rendus de 2026');
});

it('does not offer the raise on an editor the super admin belongs to', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $editor = (new EditorService)->create($admin, [
        'name' => 'Editeur de l\'exploitant',
        'contact_email' => 'exploitant@editeur.test',
    ]);

    Livewire::actingAs($admin)
        ->test(EditorList::class)
        ->call('openEditor', $editor->getKey())
        ->assertSee('relever son quota vous favoriserait')
        ->assertDontSee('Relever le quota');
});

it('refuses the raise to a moderator', function (): void {
    [, $editor] = raisedEditor();
    $moderator = User::factory()->moderator()->create();

    Livewire::actingAs($moderator)
        ->test(EditorList::class)
        ->set('editorId', $editor->getKey())
        ->set('editorQuotaCapacity', '20')
        ->set('editorQuotaUntil', now()->addDays(5)->format('Y-m-d'))
        ->set('editorQuotaMotive', 'x')
        ->call('saveQuotaRaise')
        ->assertForbidden();

    expect($editor->refresh()->quota_override_until)->toBeNull();
});

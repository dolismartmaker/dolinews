<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditEntry;
use App\Livewire\Admin\EditorList;
use App\Livewire\Admin\UserList;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * Every back-office act leaves a trace (SPEC 9.4, revue F6).
 */
it('journals the manual validation of an editor', function (): void {
    [, $editor] = Factory::contributorWithEditor();
    $editor->forceFill(['verified_at' => null])->save();

    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test(EditorList::class)
        ->call('validateEditor', $editor->getKey());

    expect($editor->fresh()?->verified_at)->not->toBeNull()
        ->and(AuditEntry::query()->where('action', 'editor.validated')->count())->toBe(1);
});

it('journals a restored account', function (): void {
    $target = User::factory()->suspended()->create();

    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test(UserList::class)
        ->call('unsuspend', $target->getKey());

    expect($target->fresh()?->active)->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'account.unsuspended')->count())->toBe(1);
});

it('journals both directions of the moderator flag', function (): void {
    $target = User::factory()->create();
    $admin = User::factory()->superAdmin()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('toggleModerator', $target->getKey())
        ->call('toggleModerator', $target->getKey());

    expect(AuditEntry::query()->where('action', 'moderator.added')->count())->toBe(1)
        ->and(AuditEntry::query()->where('action', 'moderator.removed')->count())->toBe(1);
});

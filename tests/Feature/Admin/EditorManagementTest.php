<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditEntry;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Models\Editor;
use App\Livewire\Admin\EditorList;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * The operator's acts on an editor and its membership (SPEC 9.1).
 *
 * Membership is normally the owner's business, from its own account
 * page. The operator needs it too, for an owner who has left or a team
 * to repair -- under the same invariants, which the back-office must not
 * be able to break any more than an owner can.
 */
it('creates an editor under a designated contributor account', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $owner = Factory::contributorWithoutEditor();

    Livewire::actingAs($admin)
        ->test(EditorList::class)
        ->call('openCreate')
        ->set('editorName', 'Atelier Doli')
        ->set('editorContactEmail', 'contact@atelier-doli.test')
        ->set('editorOwnerEmail', $owner->email)
        ->call('saveEditor')
        ->assertHasNoErrors();

    $editor = Editor::query()->where('name', 'Atelier Doli')->firstOrFail();

    expect(app(EditorService::class)->isOwner($editor, $owner))->toBeTrue()
        // Creation is not a validation: verified_at stays null until the
        // team validates (SPEC 3.3).
        ->and($editor->verified_at)->toBeNull()
        ->and(AuditEntry::query()->where('action', 'editor.created')->count())->toBe(1);
});

it('refuses an owner that holds no contribution proof', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $reader = User::factory()->create();

    // Owning an editor is a way of writing (SPEC 3.1): the account screen
    // qualifies first, the back-office does not dispense.
    Livewire::actingAs($admin)
        ->test(EditorList::class)
        ->call('openCreate')
        ->set('editorName', 'Atelier Doli')
        ->set('editorContactEmail', 'contact@atelier-doli.test')
        ->set('editorOwnerEmail', $reader->email)
        ->call('saveEditor')
        ->assertHasErrors('editorOwnerEmail');

    expect(Editor::query()->count())->toBe(0);
});

it('names the address no account answers to', function (): void {
    $admin = User::factory()->superAdmin()->create();

    Livewire::actingAs($admin)
        ->test(EditorList::class)
        ->call('openCreate')
        ->set('editorName', 'Atelier Doli')
        ->set('editorContactEmail', 'contact@atelier-doli.test')
        ->set('editorOwnerEmail', 'personne@nulle-part.test')
        ->call('saveEditor')
        ->assertHasErrors('editorOwnerEmail');

    expect(Editor::query()->count())->toBe(0);
});

it('attaches an account, then promotes it to owner', function (): void {
    $admin = User::factory()->superAdmin()->create();
    [, $editor] = Factory::contributorWithEditor();
    $colleague = Factory::contributorWithoutEditor();

    Livewire::actingAs($admin)
        ->test(EditorList::class)
        ->call('openEditor', $editor->getKey())
        ->set('editorMemberEmail', $colleague->email)
        ->call('addMember')
        ->assertHasNoErrors()
        ->call('setMemberRole', $colleague->getKey(), 'owner');

    $editors = app(EditorService::class);

    expect($editors->isOwner($editor, $colleague))->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'editor.member_attached')->count())->toBe(1)
        ->and(AuditEntry::query()->where('action', 'editor.role_changed')->count())->toBe(1);
});

it('refuses to make an owner of an account that already owns one', function (): void {
    $admin = User::factory()->superAdmin()->create();
    [, $editor] = Factory::contributorWithEditor();
    [$elsewhere] = Factory::contributorWithEditor();

    // The queue ceiling and the publication credit are counted per editor
    // (SPEC 5.3): a second owned editor would double the quota.
    Livewire::actingAs($admin)
        ->test(EditorList::class)
        ->call('openEditor', $editor->getKey())
        ->set('editorMemberEmail', $elsewhere->email)
        ->call('addMember')
        ->assertHasNoErrors()
        ->call('setMemberRole', $elsewhere->getKey(), 'owner');

    $editors = app(EditorService::class);

    expect($editors->isOwner($editor, $elsewhere))->toBeFalse()
        ->and($editors->isMember($editor, $elsewhere))->toBeTrue();
});

it('keeps an owner on every editor', function (): void {
    $admin = User::factory()->superAdmin()->create();
    [$owner, $editor] = Factory::contributorWithEditor();

    $editors = app(EditorService::class);

    // Neither demoting nor detaching the last owner: an editor without one
    // is unreachable for whoever would publish under it.
    Livewire::actingAs($admin)
        ->test(EditorList::class)
        ->call('openEditor', $editor->getKey())
        ->call('setMemberRole', $owner->getKey(), 'member');

    expect($editors->isOwner($editor, $owner))->toBeTrue();

    Livewire::actingAs($admin)
        ->test(EditorList::class)
        ->call('openEditor', $editor->getKey())
        ->call('detachMember', $owner->getKey());

    expect($editors->isMember($editor, $owner))->toBeTrue();
});

it('detaches a member without touching what it published', function (): void {
    $admin = User::factory()->superAdmin()->create();
    [$owner, $editor] = Factory::contributorWithEditor();
    $colleague = Factory::contributorWithoutEditor();

    app(EditorService::class)->attachMember($editor, $owner, $colleague);

    Livewire::actingAs($admin)
        ->test(EditorList::class)
        ->call('openEditor', $editor->getKey())
        ->call('detachMember', $colleague->getKey());

    expect(app(EditorService::class)->isMember($editor, $colleague))->toBeFalse()
        ->and(AuditEntry::query()->where('action', 'editor.member_detached')->count())->toBe(1);
});

it('saves the sheet without moving the slug', function (): void {
    $admin = User::factory()->superAdmin()->create();
    [, $editor] = Factory::contributorWithEditor();

    $slug = $editor->slug;

    Livewire::actingAs($admin)
        ->test(EditorList::class)
        ->call('openEditor', $editor->getKey())
        ->set('editorName', 'Atelier renommé')
        ->set('editorContactEmail', 'nouveau@atelier-doli.test')
        ->set('editorWebsite', 'https://atelier-doli.test')
        ->set('editorDescription', '')
        ->call('saveEditor')
        ->assertHasNoErrors();

    $saved = $editor->fresh();

    // The slug addresses the public page and the feeds: outside links point
    // at it, so a rename does not move it.
    expect($saved?->slug)->toBe($slug)
        ->and($saved?->name)->toBe('Atelier renommé')
        ->and($saved?->contact_email)->toBe('nouveau@atelier-doli.test')
        ->and($saved?->website)->toBe('https://atelier-doli.test')
        // An empty field is an absent value, not an empty string.
        ->and($saved?->description)->toBeNull()
        ->and(AuditEntry::query()->where('action', 'editor.updated')->count())->toBe(1);
});

it('withdraws a validation it granted', function (): void {
    $admin = User::factory()->superAdmin()->create();
    [, $editor] = Factory::contributorWithEditor();

    Livewire::actingAs($admin)
        ->test(EditorList::class)
        ->call('validateEditor', $editor->getKey())
        ->call('unvalidateEditor', $editor->getKey());

    expect($editor->fresh()?->verified_at)->toBeNull()
        ->and(AuditEntry::query()->where('action', 'editor.unvalidated')->count())->toBe(1);
});

it('leaves the sheet and its membership to the operator', function (): void {
    $moderator = User::factory()->moderator()->create(['email_verified_at' => now()]);
    [, $editor] = Factory::contributorWithEditor();

    // A moderator validates an editor, which SPEC 9.1 puts in its mandate,
    // and reads the directory. Withdrawing that marker, or recomposing a
    // team, is the operator's.
    Livewire::actingAs($moderator)
        ->test(EditorList::class)
        ->call('openEditor', $editor->getKey())
        ->assertForbidden();

    Livewire::actingAs($moderator)
        ->test(EditorList::class)
        ->call('unvalidateEditor', $editor->getKey())
        ->assertForbidden();

    Livewire::actingAs($moderator)
        ->test(EditorList::class)
        ->assertDontSee('Créer un éditeur')
        ->assertSee('Valider');
});

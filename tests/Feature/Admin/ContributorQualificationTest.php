<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditEntry;
use App\Domain\Dolinews\Contributors\ContributorVerificationService;
use App\Domain\Dolinews\Enums\ModerationAction;
use App\Domain\Dolinews\Enums\ProofMethod;
use App\Domain\Dolinews\Models\ContributorProof;
use App\Domain\Dolinews\Models\ModerationLog;
use App\Livewire\Admin\UserList;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * Manual validation of a contribution (SPEC 3.3), acted on from the
 * account screen: editors with no public repository have no git history
 * to be found in, and an anonymised forge address can receive no code.
 *
 * It is a qualification, never a dispensation: the proof exists, it is
 * simply established by a human instead of a one-time code.
 */
it('qualifies a reader account, which may then own an editor', function (): void {
    $reader = User::factory()->create();
    $admin = User::factory()->superAdmin()->create();

    expect($reader->isContributor())->toBeFalse();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('openAct', $reader->getKey())
        ->set('proofAddress', 'dev@editeur-proprietaire.test')
        ->set('proofMotive', 'Éditeur sans dépôt public, contribution vérifiée par téléphone.')
        ->call('grantContributor')
        ->assertHasNoErrors();

    $proof = ContributorProof::query()->where('user_id', $reader->getKey())->firstOrFail();

    expect($reader->fresh()?->isContributor())->toBeTrue()
        ->and($proof->method)->toBe(ProofMethod::MANUAL)
        // Not a moderation_log act: the enum of SPEC 4.5 lists withdrawals,
        // where this one grants a right.
        ->and(AuditEntry::query()->where('action', 'contributor.granted_manually')->count())->toBe(1);
});

it('binds the address, so a second account cannot claim it', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $first = User::factory()->create();
    $second = User::factory()->create();

    app(ContributorVerificationService::class)->grantManual($first, 'dev@editeur.test');

    // SPEC 3.4: one account per git identity, manual validations included,
    // otherwise the quota ceilings are a formality.
    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('openAct', $second->getKey())
        ->set('proofAddress', 'dev@editeur.test')
        ->set('proofMotive', 'Second compte du même éditeur, rattachement demandé.')
        ->call('grantContributor')
        ->assertHasErrors('proofAddress');

    expect($second->fresh()?->isContributor())->toBeFalse();
});

it('asks for a motive before qualifying', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $reader = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('openAct', $reader->getKey())
        ->set('proofAddress', 'dev@editeur.test')
        ->set('proofMotive', '')
        ->call('grantContributor')
        ->assertHasErrors('proofMotive');

    expect($reader->fresh()?->isContributor())->toBeFalse();
});

it('revokes a proof with its rule and motive, and logs the act', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $contributor = Factory::contributorWithoutEditor();

    $proof = ContributorProof::query()->where('user_id', $contributor->getKey())->firstOrFail();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('openAct', $contributor->getKey())
        ->set('proofRule', 'R3')
        ->set('proofMotive', 'Usurpation de la fiche d\'un autre éditeur, constatée le 2 octobre.')
        ->call('revokeProof', $proof->getKey())
        ->assertHasNoErrors();

    expect($proof->fresh()?->revoked_at)->not->toBeNull()
        ->and($contributor->fresh()?->isContributor())->toBeFalse()
        ->and(ModerationLog::query()->where('action', ModerationAction::PROOF_REVOKED)->count())->toBe(1);
});

it('refuses a revocation without a numbered rule', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $contributor = Factory::contributorWithoutEditor();

    $proof = ContributorProof::query()->where('user_id', $contributor->getKey())->firstOrFail();

    // SPEC 9.2: a sanction with no numbered rule in force is not
    // enforceable, so the form cannot be submitted without one.
    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('openAct', $contributor->getKey())
        ->set('proofRule', '')
        ->set('proofMotive', 'Motif suffisamment long pour passer la validation.')
        ->call('revokeProof', $proof->getKey())
        ->assertHasErrors('proofRule');

    expect($proof->fresh()?->revoked_at)->toBeNull();
});

it('keeps a revocation on the account it was typed for', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $open = Factory::contributorWithoutEditor();
    $other = Factory::contributorWithoutEditor();

    $otherProof = ContributorProof::query()->where('user_id', $other->getKey())->firstOrFail();

    // The motive names the open account: a forged call must not be able to
    // land the act on another one.
    expect(function () use ($admin, $open, $otherProof): void {
        Livewire::actingAs($admin)
            ->test(UserList::class)
            ->call('openAct', $open->getKey())
            ->set('proofRule', 'R3')
            ->set('proofMotive', 'Motif suffisamment long pour passer la validation.')
            ->call('revokeProof', $otherProof->getKey());
    })->toThrow(ModelNotFoundException::class);

    expect($otherProof->fresh()?->revoked_at)->toBeNull();
});

it('reserves the qualification to the operator', function (): void {
    $moderator = User::factory()->moderator()->create(['email_verified_at' => now()]);
    $reader = User::factory()->create();

    // A moderator reaches the back-office (SPEC 9.1) but neither suspends
    // an account nor qualifies one.
    Livewire::actingAs($moderator)
        ->test(UserList::class)
        ->call('openAct', $reader->getKey())
        ->set('proofAddress', 'dev@editeur.test')
        ->set('proofMotive', 'Motif suffisamment long pour passer la validation.')
        ->call('grantContributor')
        ->assertForbidden();

    expect($reader->fresh()?->isContributor())->toBeFalse();
});

it('spells out the contributor class in the account list', function (): void {
    $admin = User::factory()->superAdmin()->create();
    Factory::contributorWithoutEditor();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->assertSee('Contributeur')
        ->assertSee('oui');
});

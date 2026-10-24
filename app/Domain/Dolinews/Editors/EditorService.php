<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Editors;

use App\Core\Audit\AuditLogger;
use App\Domain\Dolinews\Enums\EditorRole;
use App\Domain\Dolinews\Models\Editor;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Editors and their membership (SPEC 4.1).
 *
 * An editor is created by its first owner; members are attached through
 * the editor_user pivot with the owner/member role. Sheet ownership
 * transfers are moderation acts (SPEC 9.5), not membership changes.
 */
class EditorService
{
    /**
     * Furthest end date of a raised quota, in days: a raise serves a
     * deposit (an archive, a catalogue), not a standing privilege.
     */
    public const QUOTA_RAISE_MAX_DAYS = 90;

    /**
     * Create an editor with its owner.
     *
     * One owned editor per account: the publication credit and the queue
     * ceiling are counted per editor (SPEC 5.3), so an account free to
     * mint editors would multiply its own quota at will. Publishing for
     * a second editor goes through attachMember, on the invitation of
     * its owner.
     *
     * Qualification is checked by the callers, which know what to answer
     * to whoever asked: the account page and the API both refuse a reader
     * account before reaching here (SPEC 3.1).
     *
     * @param  array<string, mixed>  $payload  editor fields
     *
     * @throws EditorException when the account already owns an editor
     */
    public function create(User $owner, array $payload): Editor
    {
        $this->assertOwnsNoOther($owner, null);

        return DB::transaction(function () use ($owner, $payload): Editor {
            $editor = Editor::query()->create([
                'slug' => $this->uniqueSlug((string) $payload['name']),
                'name' => (string) $payload['name'],
                'description' => $payload['description'] ?? null,
                'website' => $payload['website'] ?? null,
                'contact_email' => (string) $payload['contact_email'],
            ]);

            $editor->users()->attach($owner->getKey(), ['role' => EditorRole::OWNER->value]);

            return $editor;
        });
    }

    /**
     * Attach a member on an editor the actor owns.
     */
    public function attachMember(Editor $editor, User $actor, User $member): void
    {
        if (! $this->isOwner($editor, $actor)) {
            throw new EditorException('Seul le propriétaire peut ajouter un membre à l\'éditeur.');
        }

        $this->setRole($editor, $member, EditorRole::MEMBER);
    }

    /**
     * Attach an account to an editor, or change the role it already has.
     *
     * Three invariants, which the operator's back-office must not be able
     * to break any more than an owner can:
     *
     * - an owner is a contributor account, owning an editor being a way
     *   of writing (SPEC 3.1);
     * - an account owns at most one editor, the queue ceiling and the
     *   publication credit being counted per editor (SPEC 5.3);
     * - an editor keeps an owner, the owner being the only account that
     *   may attach the others.
     *
     * @throws EditorException when one of them would be broken
     */
    public function setRole(Editor $editor, User $user, EditorRole $role): void
    {
        if ($role === EditorRole::OWNER) {
            if (! $user->isContributor()) {
                throw new EditorException(
                    'Seul un compte contributeur peut posséder un éditeur : qualifiez-le d\'abord sur l\'écran des comptes.'
                );
            }

            $this->assertOwnsNoOther($user, $editor);
        }

        if ($role !== EditorRole::OWNER && $this->isLastOwner($editor, $user)) {
            throw new EditorException(
                'Cet éditeur n\'a pas d\'autre propriétaire : désignez-en un avant de rétrograder celui-ci.'
            );
        }

        $editor->users()->syncWithoutDetaching([
            $user->getKey() => ['role' => $role->value],
        ]);
    }

    /**
     * Detach an account from an editor: it stops publishing under it,
     * what it already published stays (SPEC 4.3, history is not rewritten
     * by a membership change).
     *
     * @throws EditorException when the account is the last owner
     */
    public function detachMember(Editor $editor, User $user): void
    {
        if ($this->isLastOwner($editor, $user)) {
            throw new EditorException(
                'Cet éditeur n\'a pas d\'autre propriétaire : désignez-en un avant de retirer celui-ci.'
            );
        }

        $editor->users()->detach($user->getKey());
    }

    /**
     * Whether this account is the only owner left on the editor.
     */
    private function isLastOwner(Editor $editor, User $user): bool
    {
        if (! $this->isOwner($editor, $user)) {
            return false;
        }

        return $editor->users()
            ->wherePivot('role', EditorRole::OWNER->value)
            ->count() <= 1;
    }

    /**
     * Guard the one-editor-per-account rule (SPEC 5.3).
     *
     * @param  Editor|null  $editor  the editor being created or promoted on,
     *                               excluded from the check
     *
     * @throws EditorException when the account already owns another editor
     */
    private function assertOwnsNoOther(User $user, ?Editor $editor): void
    {
        $owned = $this->ownedEditor($user);

        if ($owned === null || ($editor !== null && $owned->getKey() === $editor->getKey())) {
            return;
        }

        throw new EditorException(
            'Ce compte possède déjà l\'éditeur "'.$owned->name.'" : demandez à son propriétaire de vous rattacher pour publier au nom d\'un autre.'
        );
    }

    /**
     * The editor this account owns, if it owns one.
     */
    public function ownedEditor(User $user): ?Editor
    {
        /** @var Editor|null $editor */
        $editor = $user->editors()
            ->wherePivot('role', EditorRole::OWNER->value)
            ->first();

        return $editor;
    }

    /**
     * Whether an account belongs to this editor with the given role.
     */
    public function hasRole(Editor $editor, User $user, EditorRole $role): bool
    {
        return $editor->users()
            ->where('users.id', $user->getKey())
            ->wherePivot('role', $role->value)
            ->exists();
    }

    /**
     * Whether an account is the owner of this editor.
     */
    public function isOwner(Editor $editor, User $user): bool
    {
        return $this->hasRole($editor, $user, EditorRole::OWNER);
    }

    /**
     * Whether an account may publish for this editor (owner or member).
     */
    public function isMember(Editor $editor, User $user): bool
    {
        return $editor->users()
            ->where('users.id', $user->getKey())
            ->exists();
    }

    /**
     * Raise the publication quota of an editor until a date (SPEC 5.3).
     *
     * A decision that favours a party: refused to whoever belongs to the
     * editor (SPEC 9.6), as a sheet transfer is (SPEC 9.5). Always bounded
     * in time and motivated, and journalled with the values it replaces.
     * A value left null keeps the deployment default.
     *
     * @throws EditorException when the actor is not super admin or is a
     *                         member of the editor, when nothing is
     *                         raised, or when the end date or the motive
     *                         is missing or out of bounds.
     */
    public function raiseQuota(
        Editor $editor,
        User $actor,
        ?int $capacity,
        ?int $queueCeiling,
        CarbonInterface $until,
        string $motive,
    ): void {
        if (! $actor->is_super_admin) {
            throw new EditorException('Seul le super administrateur relève le quota d\'un éditeur.');
        }

        if ($this->isMember($editor, $actor)) {
            throw new EditorException('Vous êtes membre de cet éditeur : relever son quota vous favoriserait. Seule la configuration de l\'instance, commune à tous, peut le faire.');
        }

        if ($capacity === null && $queueCeiling === null) {
            throw new EditorException('Indiquez au moins une valeur à relever.');
        }

        if (! $until->isFuture() || $until->greaterThan(now()->addDays(self::QUOTA_RAISE_MAX_DAYS)->endOfDay())) {
            throw new EditorException('La date de fin doit être à venir, et à '.self::QUOTA_RAISE_MAX_DAYS.' jours au plus.');
        }

        if (trim($motive) === '') {
            throw new EditorException('Le motif est obligatoire.');
        }

        $previous = $this->quotaState($editor);

        $editor->quota_bucket_capacity = $capacity;
        $editor->quota_queue_ceiling = $queueCeiling;
        $editor->quota_override_until = Carbon::instance($until);
        $editor->save();

        app(AuditLogger::class)->log('editor.quota_raised', $editor, [
            'editor_slug' => $editor->slug,
            'previous' => $previous,
            'current' => $this->quotaState($editor),
            'motive' => trim($motive),
        ]);
    }

    /**
     * End a raised quota now. The values stay, closed by the end date:
     * the bucket is replayed with the capacity in force at each instant,
     * and wiping them would charge the editor, after the fact, for the
     * tokens it spent while the raise applied (SPEC 5.3).
     *
     * Not refused on membership: bringing an editor back to the common
     * rule favours no one.
     *
     * @throws EditorException when the actor is not super admin
     */
    public function endQuotaRaise(Editor $editor, User $actor): void
    {
        if (! $actor->is_super_admin) {
            throw new EditorException('Seul le super administrateur relève le quota d\'un éditeur.');
        }

        if (! $editor->quotaOverrideAppliesAt(now())) {
            return;
        }

        $previous = $this->quotaState($editor);

        // A second back: the end date is inclusive, and the common values
        // must apply from this very instant.
        $editor->quota_override_until = now()->subSecond();
        $editor->save();

        app(AuditLogger::class)->log('editor.quota_raise_ended', $editor, [
            'editor_slug' => $editor->slug,
            'previous' => $previous,
        ]);
    }

    /**
     * The raise of an editor as journalled.
     *
     * @return array{capacity: int|null, queue_ceiling: int|null, until: string|null}
     */
    private function quotaState(Editor $editor): array
    {
        return [
            'capacity' => $editor->quota_bucket_capacity,
            'queue_ceiling' => $editor->quota_queue_ceiling,
            'until' => $editor->quota_override_until?->toIso8601String(),
        ];
    }

    /**
     * Slug unique across editors.
     */
    public function uniqueSlug(string $name): string
    {
        $base = Str::slug(mb_substr($name, 0, 80));

        if ($base === '') {
            $base = 'editeur';
        }

        $slug = $base;
        $suffix = 1;

        while (Editor::query()->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Editors;

use App\Domain\Dolinews\Enums\EditorRole;
use App\Domain\Dolinews\Models\Editor;
use App\Models\User;
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
     * Create an editor with its owner.
     *
     * One owned editor per account: the publication credit and the queue
     * ceiling are counted per editor (SPEC 5.3), so an account free to
     * mint editors would multiply its own quota at will. Publishing for
     * a second editor goes through attachMember, on the invitation of
     * its owner.
     *
     * @param  array<string, mixed>  $payload  editor fields
     *
     * @throws EditorException when the account already owns an editor
     */
    public function create(User $owner, array $payload): Editor
    {
        if ($this->ownedEditor($owner) !== null) {
            throw new EditorException(
                'Ce compte possède déjà un éditeur : demandez à son propriétaire de vous rattacher pour publier au nom d\'un autre.'
            );
        }

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
     * Attach a member (or promote to owner) on an editor the actor owns.
     */
    public function attachMember(Editor $editor, User $actor, User $member): void
    {
        if (! $this->isOwner($editor, $actor)) {
            throw new EditorException('Seul le propriétaire peut ajouter un membre à l\'éditeur.');
        }

        $editor->users()->syncWithoutDetaching([
            $member->getKey() => ['role' => EditorRole::MEMBER->value],
        ]);
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

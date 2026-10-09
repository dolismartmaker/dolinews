{{--
    Operator acts on one editor, opened by the "Gérer" row action, plus the
    creation entry point.

    The sheet is persistent (SPEC 6.1): what is edited here carries no date,
    so it stays true as it ages. Nothing about Dolibarr compatibility belongs
    on it -- that lives on the announcement, with its date.
--}}
@php($editor = $this->editorTarget())
@php($members = $this->editorMembers())

{{-- A moderator reads the directory and validates from the row action; the
     rest of the panel is the operator's. --}}
@php($manage = $this->canManage())

@if ($manage && $editor === null && ! $this->creating)
    <div class="mb-5">
        <x-mary-button :label="__('Créer un éditeur')" wire:click="openCreate" icon="o-plus" class="btn-primary" />
    </div>
@elseif ($manage)
    <x-mary-card :title="$this->creating ? __('Nouvel éditeur') : __('Acte sur un éditeur')" class="mb-5 border border-primary/30" separator>
        @if ($editor !== null)
            <x-slot:subtitle>
                {{ $editor->name }} - {{ $editor->slug }}
                <x-mary-badge :value="$editor->verified_at === null ? __('non validé') : __('validé')"
                              class="{{ $editor->verified_at === null ? 'badge-neutral' : 'badge-info' }} badge-sm ml-1" />
            </x-slot:subtitle>
        @endif
        <x-slot:menu>
            <x-mary-button :label="__('Fermer')" wire:click="closeEditor" class="btn-sm btn-ghost" />
        </x-slot:menu>

        <div class="space-y-5">
            {{-- The values are written out: wire:model alone leaves the fields
                 blank on first render, and an operator editing a sheet would
                 face an empty form. --}}
            <form wire:submit="saveEditor" class="space-y-4">
                @if ($this->creating)
                    {{-- Owning an editor is writing, so the owner holds a
                         contribution proof (SPEC 3.1). --}}
                    <x-mary-input :label="__('Propriétaire')" type="email" wire:model="editorOwnerEmail" value="{{ $this->editorOwnerEmail }}"
                                  :hint="__('Adresse d\'un compte contributeur : posséder un éditeur, c\'est y publier.')" />
                @endif

                {{-- The slug addresses the public page and the feeds. --}}
                <x-mary-input :label="__('Nom')" maxlength="150" wire:model="editorName" value="{{ $this->editorName }}"
                              :hint="$this->creating ? null : __('Le slug ne suit pas le nom : il adresse la page publique et les flux.')" />
                <x-mary-input :label="__('Adresse de contact')" type="email" maxlength="255" wire:model="editorContactEmail" value="{{ $this->editorContactEmail }}" />
                <x-mary-input :label="__('Site')" type="url" maxlength="255" wire:model="editorWebsite" value="{{ $this->editorWebsite }}" />
                <x-mary-textarea :label="__('Description')" wire:model="editorDescription" rows="3">{{ $this->editorDescription }}</x-mary-textarea>

                <div class="flex flex-wrap gap-2">
                    <x-mary-button type="submit" :label="$this->creating ? __('Créer l\'éditeur') : __('Enregistrer la fiche')" class="btn-primary" spinner="saveEditor" />

                    @if ($editor !== null)
                        @if ($editor->verified_at === null)
                            <x-mary-button :label="__('Valider l\'éditeur')" wire:click="validateEditor({{ $editor->getKey() }})" class="btn-outline" spinner />
                        @else
                            <x-mary-button :label="__('Retirer la validation')" wire:click="unvalidateEditor({{ $editor->getKey() }})" class="btn-outline" spinner />
                        @endif
                    @endif
                </div>
            </form>

            @if ($editor !== null)
                {{--
                    Membership. An editor always keeps an owner: it is the only
                    role that may attach the others from the account page, and
                    an account owns at most one editor, the queue ceiling being
                    counted per editor (SPEC 5.3).
                --}}
                <div class="border-t border-base-300 pt-4">
                    <h3 class="text-sm font-semibold">{{ __('Comptes rattachés') }}</h3>

                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($members as $member)
                            <li class="flex flex-wrap items-center gap-2" wire:key="member-{{ $member->getKey() }}">
                                <span>
                                    {{ $member->name }} - {{ $member->email }}
                                    <x-mary-badge :value="$member->pivot->role === 'owner' ? __('propriétaire') : __('membre')"
                                                  class="{{ $member->pivot->role === 'owner' ? 'badge-info' : 'badge-neutral' }} badge-sm ml-1" />
                                </span>

                                @if ($member->pivot->role === 'owner')
                                    <x-mary-button :label="__('Rétrograder en membre')" wire:click="setMemberRole({{ $member->getKey() }}, 'member')" class="btn-sm btn-outline" spinner />
                                @else
                                    <x-mary-button :label="__('Promouvoir propriétaire')" wire:click="setMemberRole({{ $member->getKey() }}, 'owner')" class="btn-sm btn-outline" spinner />
                                @endif

                                <x-mary-button :label="__('Retirer')" wire:click="detachMember({{ $member->getKey() }})" class="btn-sm btn-error" spinner />
                            </li>
                        @endforeach

                        @if ($members->isEmpty())
                            <li class="text-base-content/70">{{ __('Aucun compte rattaché.') }}</li>
                        @endif
                    </ul>

                    <form wire:submit="addMember" class="mt-4 space-y-2">
                        <x-mary-input :label="__('Rattacher un compte')" type="email" wire:model="editorMemberEmail" value="{{ $this->editorMemberEmail }}" />
                        <x-mary-button type="submit" :label="__('Rattacher')" class="btn-outline" spinner="addMember" />
                    </form>

                    {{-- Detaching takes away the right to publish under the
                         editor, never what was published: the fil is not
                         rewritten by a membership change (SPEC 4.3). --}}
                    <p class="mt-3 text-sm text-base-content/70">
                        {{ __('Retirer un compte lui ôte le droit de publier sous cet éditeur. Ses articles déjà parus restent en place.') }}
                    </p>
                </div>
            @endif
        </div>
    </x-mary-card>
@endif

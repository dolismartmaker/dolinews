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
        <button type="button" class="btn btn-primary" wire:click="openCreate">
            {{ __('Créer un éditeur') }}
        </button>
    </div>
@elseif ($manage)
    <div class="card mb-5 border-accent-200 dark:border-accent-800">
        <div class="card-body space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="card-title">
                        {{ $this->creating ? __('Nouvel éditeur') : __('Acte sur un éditeur') }}
                    </h2>
                    @if ($editor !== null)
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {{ $editor->name }} - {{ $editor->slug }}
                            <span class="badge {{ $editor->verified_at === null ? 'badge-neutral' : 'badge-info' }} ml-1">
                                {{ $editor->verified_at === null ? __('non validé') : __('validé') }}
                            </span>
                        </p>
                    @endif
                </div>

                <button type="button" class="btn btn-sm btn-ghost" wire:click="closeEditor">
                    {{ __('Fermer') }}
                </button>
            </div>

            <form wire:submit="saveEditor" class="space-y-4">
                @if ($this->creating)
                    <div class="form-control">
                        <label class="label" for="editor-owner">{{ __('Propriétaire') }}</label>
                        <input id="editor-owner" class="input @error('editorOwnerEmail') input-error @enderror" type="email" wire:model="editorOwnerEmail" value="{{ $this->editorOwnerEmail }}">
                        {{-- Owning an editor is writing, so the owner holds a
                             contribution proof (SPEC 3.1). --}}
                        <p class="field-hint">{{ __('Adresse d\'un compte contributeur : posséder un éditeur, c\'est y publier.') }}</p>
                        @error('editorOwnerEmail') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div class="form-control">
                    <label class="label" for="editor-name">{{ __('Nom') }}</label>
                    {{-- The values are written out: wire:model alone leaves the
                         fields blank on first render, and an operator editing a
                         sheet would face an empty form. --}}
                    <input id="editor-name" class="input @error('editorName') input-error @enderror" type="text" maxlength="150" wire:model="editorName" value="{{ $this->editorName }}">
                    @if (! $this->creating)
                        {{-- The slug addresses the public page and the feeds. --}}
                        <p class="field-hint">{{ __('Le slug ne suit pas le nom : il adresse la page publique et les flux.') }}</p>
                    @endif
                    @error('editorName') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="form-control">
                    <label class="label" for="editor-contact">{{ __('Adresse de contact') }}</label>
                    <input id="editor-contact" class="input @error('editorContactEmail') input-error @enderror" type="email" maxlength="255" wire:model="editorContactEmail" value="{{ $this->editorContactEmail }}">
                    @error('editorContactEmail') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="form-control">
                    <label class="label" for="editor-website">{{ __('Site') }}</label>
                    <input id="editor-website" class="input @error('editorWebsite') input-error @enderror" type="url" maxlength="255" wire:model="editorWebsite" value="{{ $this->editorWebsite }}">
                    @error('editorWebsite') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="form-control">
                    <label class="label" for="editor-description">{{ __('Description') }}</label>
                    <textarea id="editor-description" class="input @error('editorDescription') input-error @enderror" rows="3" wire:model="editorDescription">{{ $this->editorDescription }}</textarea>
                    @error('editorDescription') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">
                        {{ $this->creating ? __('Créer l\'éditeur') : __('Enregistrer la fiche') }}
                    </button>

                    @if ($editor !== null)
                        @if ($editor->verified_at === null)
                            <button type="button" class="btn btn-outline" wire:click="validateEditor({{ $editor->getKey() }})">
                                {{ __('Valider l\'éditeur') }}
                            </button>
                        @else
                            <button type="button" class="btn btn-outline" wire:click="unvalidateEditor({{ $editor->getKey() }})">
                                {{ __('Retirer la validation') }}
                            </button>
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
                <div class="border-t border-slate-200 pt-4 dark:border-slate-700">
                    <h3 class="text-sm font-semibold">{{ __('Comptes rattachés') }}</h3>

                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($members as $member)
                            <li class="flex flex-wrap items-center gap-2" wire:key="member-{{ $member->getKey() }}">
                                <span>
                                    {{ $member->name }} - {{ $member->email }}
                                    <span class="badge {{ $member->pivot->role === 'owner' ? 'badge-info' : 'badge-neutral' }} ml-1">
                                        {{ $member->pivot->role === 'owner' ? __('propriétaire') : __('membre') }}
                                    </span>
                                </span>

                                @if ($member->pivot->role === 'owner')
                                    <button type="button" class="btn btn-sm btn-outline" wire:click="setMemberRole({{ $member->getKey() }}, 'member')">
                                        {{ __('Rétrograder en membre') }}
                                    </button>
                                @else
                                    <button type="button" class="btn btn-sm btn-outline" wire:click="setMemberRole({{ $member->getKey() }}, 'owner')">
                                        {{ __('Promouvoir propriétaire') }}
                                    </button>
                                @endif

                                <button type="button" class="btn btn-sm btn-danger" wire:click="detachMember({{ $member->getKey() }})">
                                    {{ __('Retirer') }}
                                </button>
                            </li>
                        @endforeach

                        @if ($members->isEmpty())
                            <li class="text-slate-500 dark:text-slate-400">{{ __('Aucun compte rattaché.') }}</li>
                        @endif
                    </ul>

                    <form wire:submit="addMember" class="mt-4 space-y-2">
                        <div class="form-control">
                            <label class="label" for="editor-member">{{ __('Rattacher un compte') }}</label>
                            <input id="editor-member" class="input @error('editorMemberEmail') input-error @enderror" type="email" wire:model="editorMemberEmail" value="{{ $this->editorMemberEmail }}">
                            @error('editorMemberEmail') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        <button type="submit" class="btn btn-outline">{{ __('Rattacher') }}</button>
                    </form>

                    {{-- Detaching takes away the right to publish under the
                         editor, never what was published: the fil is not
                         rewritten by a membership change (SPEC 4.3). --}}
                    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Retirer un compte lui ôte le droit de publier sous cet éditeur. Ses articles déjà parus restent en place.') }}
                    </p>
                </div>
            @endif
        </div>
    </div>
@endif

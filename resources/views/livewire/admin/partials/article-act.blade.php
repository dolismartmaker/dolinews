{{--
    Moderation acts on one article, opened by the "Modérer" row action.

    Masking and withdrawing are withdrawal acts: they go through whatever the
    conflict of interest, because the operator is the editor of the validated
    contents and must be able to withdraw (SPEC 9.6/9.7). Declaring the
    conflict does not block the act, it triggers the confirmation by a second
    moderator within seven days.
--}}
@php($target = $this->actTarget())

@if ($target !== null)
    <x-mary-card :title="__('Acte de modération')" class="mb-5 border border-primary/30" separator>
        <x-slot:subtitle>
            {{ $target->title }}
            <x-mary-badge :value="$target->status->label()" class="badge-sm ml-1" />
            @if ($target->deleted_at !== null)
                <x-mary-badge :value="__('retiré')" class="badge-error badge-sm ml-1" />
            @endif
        </x-slot:subtitle>
        <x-slot:menu>
            <x-mary-button :label="__('Fermer')" wire:click="closeAct" class="btn-sm btn-ghost" />
        </x-slot:menu>

        <div class="space-y-4">
            <form wire:submit="applyAct" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-mary-select :label="__('Acte')" wire:model="actKind" :options="[
                        ['id' => 'hide', 'name' => __('Masquer')],
                        ['id' => 'unhide', 'name' => __('Démasquer')],
                        ['id' => 'delete', 'name' => __('Retirer')],
                        ['id' => 'restore', 'name' => __('Rétablir')],
                    ]" />
                    <x-mary-input :label="__('Règle invoquée')" wire:model="actRule" maxlength="20" placeholder="R1, R2, ..."
                                  :hint="__('Obligatoire : une sanction sans règle numérotée n\'est pas applicable.')" />
                </div>

                <x-mary-textarea :label="__('Motif')" wire:model="actMotive" rows="3" />

                <div class="space-y-2">
                    <x-mary-checkbox :label="__('Je suis en conflit d\'intérêts sur cet article (l\'acte passe, et devra être confirmé par un second modérateur sous sept jours).')" wire:model="actConflict" />
                    <x-mary-checkbox :label="__('Acte pris sur injonction légale.')" wire:model="actLegal" />
                </div>

                <x-mary-button type="submit" :label="__('Appliquer l\'acte')" class="btn-error" spinner="applyAct" />
            </form>

            @if (auth('web')->user()?->is_super_admin)
                {{--
                    Filing under a sheet. Not a moderation act and not a
                    revision: the text does not change, so the announcement
                    carries no correction mention and the review is not asked
                    for anything. Reserved to the operator all the same, and
                    motivated like any act that changes the state of the
                    service (SPEC 9.4).
                --}}
                <div class="border-t border-base-300 pt-4">
                    <h3 class="text-sm font-semibold">{{ __('Fiche projet') }}</h3>
                    <p class="mt-1 text-sm text-base-content/70">
                        {{ __('Ranger une annonce ne la corrige pas : son texte ne bouge pas, elle ne repasse pas par la revue et ne porte aucune mention de correction.') }}
                    </p>

                    <form wire:submit="linkProject" class="mt-3 space-y-4">
                        {{-- Only this editor's sheets: filing under another
                             editor's would be a claim (SPEC 9.5). --}}
                        <x-mary-select :label="__('Fiche')" wire:model="linkProjectId" :placeholder="__('Aucune fiche')" placeholder-value=""
                                       :options="$this->linkableProjects()" option-value="id" option-label="name"
                                       :hint="__('Les fiches de l\'éditeur de cette annonce. Rattacher sous la fiche d\'un autre éditeur serait une revendication, qui a son propre circuit.')" />
                        <x-mary-textarea :label="__('Motif')" wire:model="linkMotive" rows="2">{{ $this->linkMotive }}</x-mary-textarea>

                        @if ($this->linkGroupSize() > 1)
                            {{-- project_id is borne by each article: the whole
                                 group moves, or the sheet lists one language. --}}
                            <p class="text-sm text-base-content/70">
                                {{ __('Cette annonce compte :count versions linguistiques : toutes seront rangées ensemble.', ['count' => $this->linkGroupSize()]) }}
                            </p>
                        @endif

                        <x-mary-button type="submit" :label="__('Ranger l\'annonce')" class="btn-outline" spinner="linkProject" />
                    </form>
                </div>
            @endif
        </div>
    </x-mary-card>
@endif

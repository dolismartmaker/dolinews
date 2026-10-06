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
    <div class="card mb-5 border-accent-200 dark:border-accent-800">
        <div class="card-body space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="card-title">{{ __('Acte de modération') }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {{ $target->title }}
                        <span class="badge ml-1">{{ $target->status->label() }}</span>
                        @if ($target->deleted_at !== null)
                            <span class="badge badge-danger ml-1">{{ __('retiré') }}</span>
                        @endif
                    </p>
                </div>

                <button type="button" class="btn btn-sm btn-ghost" wire:click="closeAct">
                    {{ __('Fermer') }}
                </button>
            </div>

            <form wire:submit="applyAct" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="form-control">
                        <label class="label" for="act-kind">{{ __('Acte') }}</label>
                        <select id="act-kind" class="input" wire:model="actKind">
                            <option value="hide">{{ __('Masquer') }}</option>
                            <option value="unhide">{{ __('Démasquer') }}</option>
                            <option value="delete">{{ __('Retirer') }}</option>
                            <option value="restore">{{ __('Rétablir') }}</option>
                        </select>
                        @error('actKind') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="form-control">
                        <label class="label" for="act-rule">{{ __('Règle invoquée') }}</label>
                        <input id="act-rule" class="input @error('actRule') input-error @enderror" type="text" maxlength="20" wire:model="actRule" placeholder="R1, R2, ...">
                        <p class="field-hint">{{ __('Obligatoire : une sanction sans règle numérotée n\'est pas applicable.') }}</p>
                        @error('actRule') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="form-control">
                    <label class="label" for="act-motive">{{ __('Motif') }}</label>
                    <textarea id="act-motive" class="input @error('actMotive') input-error @enderror" rows="3" wire:model="actMotive"></textarea>
                    @error('actMotive') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="space-y-2 text-sm">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" class="mt-1" wire:model="actConflict">
                        <span>{{ __('Je suis en conflit d\'intérêts sur cet article (l\'acte passe, et devra être confirmé par un second modérateur sous sept jours).') }}</span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" class="mt-1" wire:model="actLegal">
                        <span>{{ __('Acte pris sur injonction légale.') }}</span>
                    </label>
                </div>

                <button type="submit" class="btn btn-danger">{{ __('Appliquer l\'acte') }}</button>
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
                <div class="border-t border-slate-200 pt-4 dark:border-slate-700">
                    <h3 class="text-sm font-semibold">{{ __('Fiche projet') }}</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Ranger une annonce ne la corrige pas : son texte ne bouge pas, elle ne repasse pas par la revue et ne porte aucune mention de correction.') }}
                    </p>

                    <form wire:submit="linkProject" class="mt-3 space-y-4">
                        <div class="form-control">
                            <label class="label" for="link-project">{{ __('Fiche') }}</label>
                            <select id="link-project" class="input @error('linkProjectId') input-error @enderror" wire:model="linkProjectId">
                                <option value="">{{ __('Aucune fiche') }}</option>
                                @foreach ($this->linkableProjects() as $project)
                                    <option value="{{ $project->getKey() }}" @selected((string) $project->getKey() === $this->linkProjectId)>{{ $project->name }}</option>
                                @endforeach
                            </select>
                            {{-- Only this editor's sheets: filing under another
                                 editor's would be a claim (SPEC 9.5). --}}
                            <p class="field-hint">{{ __('Les fiches de l\'éditeur de cette annonce. Rattacher sous la fiche d\'un autre éditeur serait une revendication, qui a son propre circuit.') }}</p>
                            @error('linkProjectId') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="form-control">
                            <label class="label" for="link-motive">{{ __('Motif') }}</label>
                            <textarea id="link-motive" class="input @error('linkMotive') input-error @enderror" rows="2" wire:model="linkMotive">{{ $this->linkMotive }}</textarea>
                            @error('linkMotive') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        @if ($this->linkGroupSize() > 1)
                            {{-- project_id is borne by each article: the whole
                                 group moves, or the sheet lists one language. --}}
                            <p class="text-sm text-slate-500 dark:text-slate-400">
                                {{ __('Cette annonce compte :count versions linguistiques : toutes seront rangées ensemble.', ['count' => $this->linkGroupSize()]) }}
                            </p>
                        @endif

                        <button type="submit" class="btn btn-outline">{{ __('Ranger l\'annonce') }}</button>
                    </form>
                </div>
            @endif
        </div>
    </div>
@endif

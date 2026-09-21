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
        </div>
    </div>
@endif

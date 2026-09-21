{{--
    Moderation acts on one account, opened by the "Gérer" row action.

    A suspension is a withdrawal act: it goes through whatever the conflict of
    interest, and is confirmed by a second moderator within seven days when
    taken in one (SPEC 9.6). Hence the two declarations below rather than a
    plain confirm dialog: the act cannot be journalled without them.
--}}
@php($target = $this->actTarget())

@if ($target !== null)
    <div class="card mb-5 border-accent-200 dark:border-accent-800">
        <div class="card-body space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="card-title">{{ __('Acte sur un compte') }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {{ $target->name }} - {{ $target->email }}
                        @if (! $target->active)
                            <span class="badge badge-danger ml-1">{{ __('suspendu') }}</span>
                        @endif
                        @if ($target->is_moderator)
                            <span class="badge badge-info ml-1">{{ __('modérateur') }}</span>
                        @endif
                    </p>
                </div>

                <button type="button" class="btn btn-sm btn-ghost" wire:click="closeAct">
                    {{ __('Fermer') }}
                </button>
            </div>

            @if ($target->active)
                <form wire:submit="suspend" class="space-y-4">
                    <div class="form-control">
                        <label class="label" for="act-rule">{{ __('Règle invoquée') }}</label>
                        <input id="act-rule" class="input @error('actRule') input-error @enderror" type="text" maxlength="20" wire:model="actRule" placeholder="R1, R2, ...">
                        {{-- A sanction without a numbered rule in force at the
                             time of the facts is not enforceable (SPEC 9.2). --}}
                        <p class="field-hint">{{ __('Obligatoire : une sanction sans règle numérotée n\'est pas applicable.') }}</p>
                        @error('actRule') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="form-control">
                        <label class="label" for="act-motive">{{ __('Motif') }}</label>
                        <textarea id="act-motive" class="input @error('actMotive') input-error @enderror" rows="3" wire:model="actMotive"></textarea>
                        @error('actMotive') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="space-y-2 text-sm">
                        <label class="flex items-start gap-2">
                            <input type="checkbox" class="mt-1" wire:model="actConflict">
                            <span>{{ __('Je suis en conflit d\'intérêts sur ce compte (l\'acte passe, et devra être confirmé par un second modérateur sous sept jours).') }}</span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input type="checkbox" class="mt-1" wire:model="actLegal">
                            <span>{{ __('Acte pris sur injonction légale.') }}</span>
                        </label>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-danger">{{ __('Suspendre le compte') }}</button>
                        <button type="button" class="btn btn-outline" wire:click="toggleModerator({{ $target->getKey() }})">
                            {{ $target->is_moderator ? __('Retirer de l\'équipe de modération') : __('Ajouter à l\'équipe de modération') }}
                        </button>
                    </div>
                </form>
            @else
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn btn-primary" wire:click="unsuspend({{ $target->getKey() }})">
                        {{ __('Rétablir le compte') }}
                    </button>
                    <button type="button" class="btn btn-outline" wire:click="toggleModerator({{ $target->getKey() }})">
                        {{ $target->is_moderator ? __('Retirer de l\'équipe de modération') : __('Ajouter à l\'équipe de modération') }}
                    </button>
                </div>
            @endif
        </div>
    </div>
@endif

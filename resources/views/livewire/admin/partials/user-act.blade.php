{{--
    Moderation acts on one account, opened by the "Gérer" row action.

    A suspension is a withdrawal act: it goes through whatever the conflict of
    interest, and is confirmed by a second moderator within seven days when
    taken in one (SPEC 9.6). Hence the two declarations below rather than a
    plain confirm dialog: the act cannot be journalled without them.
--}}
@php($target = $this->actTarget())
@php($proofs = $this->targetProofs())

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
                        <span class="badge {{ $proofs->isEmpty() ? 'badge-neutral' : 'badge-info' }} ml-1">
                            {{ $proofs->isEmpty() ? __('compte lecteur') : __('compte contributeur') }}
                        </span>
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

            {{--
                The contributor class (SPEC 3.1), granted here by the manual
                validation of SPEC 3.3. Granting is not a moderation_log act -
                the action enum of SPEC 4.5 is closed and lists withdrawals -
                where revoking is one, hence the numbered rule it asks for.
            --}}
            <div class="border-t border-slate-200 pt-4 dark:border-slate-700">
                <h3 class="text-sm font-semibold">{{ __('Preuve de contribution') }}</h3>

                @if ($proofs->isEmpty())
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('La validation manuelle est la voie des éditeurs sans dépôt public, et des adresses de forge anonymisées auxquelles aucun code ne peut être envoyé.') }}
                    </p>

                    <form wire:submit="grantContributor" class="mt-3 space-y-4">
                        <div class="form-control">
                            <label class="label" for="proof-address">{{ __('Adresse de commit') }}</label>
                            {{-- The value is written out: wire:model alone leaves
                                 the field blank on first render, and the address
                                 the panel pre-filled would not show. --}}
                            <input id="proof-address" class="input @error('proofAddress') input-error @enderror" type="email" maxlength="255" wire:model="proofAddress" value="{{ $this->proofAddress }}">
                            {{-- Only the hash is kept, and it stays bound to this
                                 account: no second account on the same identity
                                 (SPEC 3.4). --}}
                            <p class="field-hint">{{ __('Jamais conservée en clair, et liée définitivement à ce compte.') }}</p>
                            @error('proofAddress') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="form-control">
                            <label class="label" for="proof-motive">{{ __('Motif') }}</label>
                            <textarea id="proof-motive" class="input @error('proofMotive') input-error @enderror" rows="2" wire:model="proofMotive"></textarea>
                            @error('proofMotive') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        <button type="submit" class="btn btn-primary">{{ __('Qualifier en contributeur') }}</button>
                    </form>
                @else
                    {{-- Said in full: the suspension form just above carries the
                         same two labels, and nothing else tells which act the
                         rule being typed belongs to. --}}
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('La révocation retire le droit d\'écrire : elle se motive comme tout acte de modération.') }}
                    </p>

                    <div class="mt-3 space-y-4">
                        <div class="form-control">
                            <label class="label" for="proof-rule">{{ __('Règle invoquée') }}</label>
                            <input id="proof-rule" class="input @error('proofRule') input-error @enderror" type="text" maxlength="20" wire:model="proofRule" placeholder="R1, R2, ...">
                            @error('proofRule') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="form-control">
                            <label class="label" for="proof-revoke-motive">{{ __('Motif') }}</label>
                            <textarea id="proof-revoke-motive" class="input @error('proofMotive') input-error @enderror" rows="2" wire:model="proofMotive"></textarea>
                            @error('proofMotive') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        <label class="flex items-start gap-2 text-sm">
                            <input type="checkbox" class="mt-1" wire:model="proofConflict">
                            <span>{{ __('Je suis en conflit d\'intérêts sur ce compte (l\'acte passe, et devra être confirmé par un second modérateur sous sept jours).') }}</span>
                        </label>

                        <ul class="space-y-2 text-sm">
                            @foreach ($proofs as $proof)
                                <li class="flex flex-wrap items-center gap-2" wire:key="proof-{{ $proof->getKey() }}">
                                    <span>
                                        {{ $proof->method->label() }}
                                        @if ($proof->source_repo !== 'manual')
                                            - {{ $proof->source_repo }}
                                        @endif
                                        - {{ $proof->verified_at->format('d/m/Y') }}
                                    </span>
                                    <button type="button" class="btn btn-sm btn-danger" wire:click="revokeProof({{ $proof->getKey() }})">
                                        {{ __('Révoquer') }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>

                        {{-- The row survives the revocation, which is what keeps
                             the address bound (SPEC 3.4/9.8). --}}
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            {{ __('Une preuve révoquée reste en base : son adresse demeure liée à ce compte, et ne peut pas servir à en ouvrir un autre.') }}
                        </p>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endif

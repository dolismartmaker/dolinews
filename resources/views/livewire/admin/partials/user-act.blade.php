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
    <x-mary-card :title="__('Acte sur un compte')" class="mb-5 border border-primary/30" separator>
        <x-slot:subtitle>
            {{ $target->name }} - {{ $target->email }}
            @if (! $target->active)
                <x-mary-badge :value="__('suspendu')" class="badge-error badge-sm ml-1" />
            @endif
            @if ($target->is_moderator)
                <x-mary-badge :value="__('modérateur')" class="badge-info badge-sm ml-1" />
            @endif
            <x-mary-badge :value="$proofs->isEmpty() ? __('compte lecteur') : __('compte contributeur')"
                          class="{{ $proofs->isEmpty() ? 'badge-neutral' : 'badge-info' }} badge-sm ml-1" />
        </x-slot:subtitle>
        <x-slot:menu>
            <x-mary-button :label="__('Fermer')" wire:click="closeAct" class="btn-sm btn-ghost" />
        </x-slot:menu>

        <div class="space-y-4">
            @if ($target->active)
                <form wire:submit="suspend" class="space-y-4">
                    {{-- A sanction without a numbered rule in force at the
                         time of the facts is not enforceable (SPEC 9.2). --}}
                    <x-mary-input :label="__('Règle invoquée')" wire:model="actRule" maxlength="20" placeholder="R1, R2, ..."
                                  :hint="__('Obligatoire : une sanction sans règle numérotée n\'est pas applicable.')" />
                    <x-mary-textarea :label="__('Motif')" wire:model="actMotive" rows="3" />

                    <div class="space-y-2">
                        <x-mary-checkbox :label="__('Je suis en conflit d\'intérêts sur ce compte (l\'acte passe, et devra être confirmé par un second modérateur sous sept jours).')" wire:model="actConflict" />
                        <x-mary-checkbox :label="__('Acte pris sur injonction légale.')" wire:model="actLegal" />
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <x-mary-button type="submit" :label="__('Suspendre le compte')" class="btn-error" spinner="suspend" />
                        <x-mary-button :label="$target->is_moderator ? __('Retirer de l\'équipe de modération') : __('Ajouter à l\'équipe de modération')"
                                       wire:click="toggleModerator({{ $target->getKey() }})" class="btn-outline" spinner />
                    </div>
                </form>
            @else
                <div class="flex flex-wrap gap-2">
                    <x-mary-button :label="__('Rétablir le compte')" wire:click="unsuspend({{ $target->getKey() }})" class="btn-primary" spinner />
                    <x-mary-button :label="$target->is_moderator ? __('Retirer de l\'équipe de modération') : __('Ajouter à l\'équipe de modération')"
                                   wire:click="toggleModerator({{ $target->getKey() }})" class="btn-outline" spinner />
                </div>
            @endif

            {{--
                The contributor class (SPEC 3.1), granted here by the manual
                validation of SPEC 3.3. Granting is not a moderation_log act -
                the action enum of SPEC 4.5 is closed and lists withdrawals -
                where revoking is one, hence the numbered rule it asks for.
            --}}
            <div class="border-t border-base-300 pt-4">
                <h3 class="text-sm font-semibold">{{ __('Preuve de contribution') }}</h3>

                @if ($proofs->isEmpty())
                    <p class="mt-1 text-sm text-base-content/70">
                        {{ __('La validation manuelle est la voie des éditeurs sans dépôt public, et des adresses de forge anonymisées auxquelles aucun code ne peut être envoyé.') }}
                    </p>

                    <form wire:submit="grantContributor" class="mt-3 space-y-4">
                        {{-- The value is written out: wire:model alone leaves the
                             field blank on first render, and the address the
                             panel pre-filled would not show. Only the hash is
                             kept, bound to this account (SPEC 3.4). --}}
                        <x-mary-input :label="__('Adresse de commit')" type="email" maxlength="255" wire:model="proofAddress" value="{{ $this->proofAddress }}"
                                      :hint="__('Jamais conservée en clair, et liée définitivement à ce compte.')" />
                        <x-mary-textarea :label="__('Motif')" wire:model="proofMotive" rows="2" />

                        <x-mary-button type="submit" :label="__('Qualifier en contributeur')" class="btn-primary" spinner="grantContributor" />
                    </form>
                @else
                    {{-- Said in full: the suspension form just above carries the
                         same two labels, and nothing else tells which act the
                         rule being typed belongs to. --}}
                    <p class="mt-1 text-sm text-base-content/70">
                        {{ __('La révocation retire le droit d\'écrire : elle se motive comme tout acte de modération.') }}
                    </p>

                    <div class="mt-3 space-y-4">
                        <x-mary-input :label="__('Règle invoquée')" wire:model="proofRule" maxlength="20" placeholder="R1, R2, ..." />
                        <x-mary-textarea :label="__('Motif')" wire:model="proofMotive" rows="2" />
                        <x-mary-checkbox :label="__('Je suis en conflit d\'intérêts sur ce compte (l\'acte passe, et devra être confirmé par un second modérateur sous sept jours).')" wire:model="proofConflict" />

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
                                    <x-mary-button :label="__('Révoquer')" wire:click="revokeProof({{ $proof->getKey() }})" class="btn-sm btn-error" spinner />
                                </li>
                            @endforeach
                        </ul>

                        {{-- The row survives the revocation, which is what keeps
                             the address bound (SPEC 3.4/9.8). --}}
                        <p class="text-sm text-base-content/70">
                            {{ __('Une preuve révoquée reste en base : son adresse demeure liée à ce compte, et ne peut pas servir à en ouvrir un autre.') }}
                        </p>
                    </div>
                @endif
            </div>
        </div>
    </x-mary-card>
@endif

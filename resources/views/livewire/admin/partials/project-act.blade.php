{{--
    A project sheet opened by the "Ouvrir" row action: what it says, where
    its links go, and the acts the team holds on it.

    Masking is a withdrawal act: it goes through whatever the conflict of
    interest, because the operator answers for what the service shows and
    must be able to withdraw (SPEC 9.6/9.7). Declaring the conflict does not
    block the act, it triggers the confirmation by a second moderator within
    seven days. Nobody rewrites the sheet from here: it is the editor's
    text, the team masks and the editor corrects.
--}}
@php($target = $this->actTarget())

@if (auth('web')->user()?->is_super_admin)
    {{-- Domains accepted for every sheet (SPEC 8): a link there raises no
         domain alert. The list grows from the flagged links of a sheet,
         and shrinks from here. --}}
    @php($acceptedDomains = $this->acceptedDomains())
    <details class="mb-5 rounded-box border border-base-300 bg-base-100 px-4 py-3">
        <summary class="cursor-pointer text-sm font-semibold">{{ __('Domaines acceptés (:count)', ['count' => $acceptedDomains->count()]) }}</summary>
        <p class="mt-2 text-sm text-base-content/70">
            {{ __('Un lien vers l\'un de ces domaines, ou l\'un de leurs sous-domaines, ne compte plus dans l\'alerte domaine, sur toutes les fiches. On en ajoute depuis les liens hors domaine d\'une fiche ouverte.') }}
        </p>
        <ul class="mt-2 space-y-1 text-sm">
            @forelse ($acceptedDomains as $accepted)
                <li class="flex flex-wrap items-center gap-2">
                    <span class="font-mono">{{ $accepted->domain }}</span>
                    <span class="text-base-content/60">{{ $accepted->created_at?->format('d/m/Y') }}</span>
                    <x-mary-button :label="__('Retirer')" wire:click="removeDomain({{ $accepted->id }})"
                                   wire:confirm="{{ __('Retirer :domain de la liste ? Ses liens compteront de nouveau dans l\'alerte.', ['domain' => $accepted->domain]) }}"
                                   class="btn-xs btn-ghost" spinner />
                </li>
            @empty
                <li class="text-base-content/70">{{ __('Aucun domaine accepté pour l\'instant.') }}</li>
            @endforelse
        </ul>
    </details>
@endif

@if ($target !== null)
    <x-mary-card :title="__('Fiche projet')" class="mb-5 border border-primary/30" separator>
        <x-slot:subtitle>
            {{ $target->name }}
            <x-mary-badge :value="$target->status->label()" class="badge-sm ml-1" />
            @if ($target->isHidden())
                <x-mary-badge :value="__('masquée')" class="badge-error badge-sm ml-1" />
            @endif
        </x-slot:subtitle>
        <x-slot:menu>
            <x-mary-button :label="__('Fermer')" wire:click="closeAct" class="btn-sm btn-ghost" />
        </x-slot:menu>

        <div class="space-y-4">
            <dl class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[max-content_1fr]">
                <dt class="text-base-content/70">{{ __('Éditeur') }}</dt>
                <dd>{{ $target->editor?->name }}@if ($target->editor?->website !== null) - {{ $target->editor->website }}@endif</dd>
                <dt class="text-base-content/70">{{ __('Résumé') }}</dt>
                <dd>{{ $target->summary }}</dd>
                <dt class="text-base-content/70">{{ __('Page publique') }}</dt>
                <dd>
                    {{-- A masked sheet answers 404 to everyone, the team included:
                         the page cannot be shown, only described. --}}
                    @if ($target->isHidden())
                        {{ __('Masquée le :date : la page répond comme une fiche absente.', ['date' => $target->hidden_at?->format('d/m/Y H:i')]) }}
                    @else
                        <a class="link" href="{{ route('projects.show', $target->slug) }}" target="_blank" rel="noopener">{{ route('projects.show', $target->slug) }}</a>
                    @endif
                </dd>
            </dl>

            <div>
                <h3 class="text-sm font-semibold">{{ __('Liens') }}</h3>
                <ul class="mt-2 space-y-1 text-sm">
                    @forelse ($target->links as $link)
                        <li class="break-all">
                            <span class="text-base-content/70">{{ $link->type->label() }} :</span>
                            {{-- Outgoing links: nofollow ugc, no exception (D8). --}}
                            <a class="link" href="{{ $link->url }}" target="_blank" rel="nofollow ugc noopener">{{ $link->url }}</a>
                            @if ($this->isOffDomain($link->id))
                                <x-mary-badge :value="__('hors domaine')" class="badge-warning badge-sm ml-1" />
                                {{-- One button per level: the super admin chooses
                                     between the host alone and its parent domain. --}}
                                @if (auth('web')->user()?->is_super_admin)
                                    @foreach ($this->acceptableLevels($link->id) as $index => $level)
                                        <x-mary-button :label="__('Accepter :domain', ['domain' => $level])"
                                                       wire:click="acceptDomain({{ $link->id }}, {{ $index }})"
                                                       class="btn-xs btn-outline ml-1" spinner />
                                    @endforeach
                                @endif
                            @endif
                            @if ($link->is_broken)
                                <x-mary-badge :value="__('lien cassé')" class="badge-warning badge-sm ml-1" />
                            @endif
                        </li>
                    @empty
                        <li class="text-base-content/70">{{ __('Aucun lien déclaré.') }}</li>
                    @endforelse
                </ul>
            </div>

            <form wire:submit="applyAct" class="space-y-4 border-t border-base-300 pt-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-mary-select :label="__('Acte')" wire:model="actKind" :options="[
                        ['id' => 'hide', 'name' => __('Masquer la fiche')],
                        ['id' => 'unhide', 'name' => __('Rétablir la fiche')],
                    ]" />
                    <x-mary-input :label="__('Règle invoquée')" wire:model="actRule" maxlength="20" placeholder="R1, R2, ..."
                                  :hint="__('Obligatoire : une sanction sans règle numérotée n\'est pas applicable.')" />
                </div>

                <p class="text-sm text-base-content/70">
                    {{ __('Une fiche masquée disparaît du site, de la recherche, du plan du site et de l\'API. Ses annonces restent publiées, sans renvoi vers elle, et son éditeur peut toujours la corriger.') }}
                </p>

                <x-mary-textarea :label="__('Motif')" wire:model="actMotive" rows="3" />

                <div class="space-y-2">
                    <x-mary-checkbox :label="__('Je suis en conflit d\'intérêts sur cette fiche (l\'acte passe, et devra être confirmé par un second modérateur sous sept jours).')" wire:model="actConflict" />
                    <x-mary-checkbox :label="__('Acte pris sur injonction légale.')" wire:model="actLegal" />
                </div>

                <x-mary-button type="submit" :label="__('Appliquer l\'acte')" class="btn-error" spinner="applyAct" />
            </form>

            @if (auth('web')->user()?->is_super_admin)
                {{--
                    Transfer of ownership (SPEC 9.5): the heaviest act, the
                    resolution of a claim proved by the claimant. Refused to
                    a member of either editor (SPEC 9.6).
                --}}
                <div class="border-t border-base-300 pt-4">
                    <h3 class="text-sm font-semibold">{{ __('Transférer la fiche') }}</h3>
                    <p class="mt-1 text-sm text-base-content/70">
                        {{ __('Le transfert tranche une revendication : le plaignant a prouvé la propriété du projet. La fiche passe sous l\'autre éditeur ; les annonces déjà publiées restent signées par celui qui les a déposées.') }}
                    </p>

                    <form wire:submit="transfer" class="mt-3 space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-mary-select :label="__('Nouvel éditeur')" wire:model="transferEditorId" :placeholder="__('Choisir un éditeur')" placeholder-value=""
                                           :options="$this->transferTargets()" option-value="id" option-label="name" />
                            <x-mary-input :label="__('Règle invoquée')" wire:model="transferRule" maxlength="20" placeholder="R2"
                                          :hint="__('Facultative : seulement si le transfert sanctionne une usurpation.')" />
                        </div>
                        <x-mary-textarea :label="__('Motif')" wire:model="transferMotive" rows="2" />

                        <x-mary-button type="submit" :label="__('Transférer')" class="btn-outline" spinner="transfer" />
                    </form>
                </div>
            @endif
        </div>
    </x-mary-card>
@endif

{{--
    Handling one report (SPEC 9.9), and the queue's own toggle.

    The moderation act lives here rather than one screen away: a report is
    read and acted on in the same move, or it is read and forgotten. The
    act still goes through ModerationService, so it lands in
    moderation_log with its numbered rule and its motive (SPEC 9.4).
--}}
@php($report = $this->actTarget())
@php($withdraws = in_array($actKind, ['hide', 'delete'], true))

<div class="mb-5 flex flex-wrap items-center gap-3 text-sm">
    <x-mary-button :label="$this->handledLabel()" wire:click="$toggle('showHandled')" class="btn-sm btn-ghost" />
    <span class="text-base-content/70">
        {{ __('Signalements ouverts :') }} {{ $this->openCount() }}
    </span>
</div>

@if ($report !== null)
    <x-mary-card :title="__('Traiter le signalement')" class="mb-5 border border-primary/30" separator>
        <x-slot:subtitle>
            {{ $report->targetLabel() }}
            <x-mary-badge :value="$report->reason->label()" class="badge-sm ml-1" />
            <x-mary-badge :value="$report->locale" class="badge-sm ml-1" />
        </x-slot:subtitle>
        <x-slot:menu>
            <x-mary-button :label="__('Fermer')" wire:click="closeAct" class="btn-sm btn-ghost" />
        </x-slot:menu>

        <div class="space-y-4">
            @php($siblings = $this->siblingCount($report))
            @if ($siblings > 0)
                {{-- Only the first report of a target mails the team: the
                     others are here, and their number is the information
                     the mail could not carry. --}}
                <x-mary-alert :title="__('Autres signalements ouverts sur ce même contenu : :count', ['count' => $siblings])" icon="o-exclamation-triangle" class="alert-warning" />
            @endif

            <div class="rounded-box bg-base-200 p-4 text-sm">
                <p class="whitespace-pre-line">{{ $report->body }}</p>
                <p class="mt-3 text-base-content/70">
                    {{ __('Signalant :') }} {{ $report->reporter_email }}
                    @if ($report->targetUrl() !== null)
                        - <a class="link" href="{{ $report->targetUrl() }}" target="_blank" rel="noopener">{{ __('lire le contenu signalé') }}</a>
                    @endif
                </p>
            </div>

            <form wire:submit="applyAct" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-mary-select :label="__('Suite donnée')" wire:model.live="actKind" :options="array_values(array_filter([
                        $report->article_id !== null ? ['id' => 'hide', 'name' => __('Masquer l\'article et clore')] : null,
                        $report->article_id !== null ? ['id' => 'delete', 'name' => __('Retirer l\'article et clore')] : null,
                        ['id' => 'actioned', 'name' => __('Acte pris ailleurs, clore')],
                        ['id' => 'dismiss', 'name' => __('Classer sans suite')],
                    ]))" />

                    @if ($withdraws)
                        <x-mary-input :label="__('Règle invoquée')" wire:model="actRule" maxlength="20" placeholder="R1, R2, ..."
                                      :hint="__('Obligatoire : une sanction sans règle numérotée n\'est pas applicable.')" />
                    @endif
                </div>

                <x-mary-textarea :label="__('Motif')" wire:model="actMotive" rows="3"
                                 :hint="__('Lu par l\'auteur du contenu en cas de contestation, jamais par le signalant.')" />

                @if ($withdraws)
                    <div class="space-y-2">
                        <x-mary-checkbox :label="__('Je suis en conflit d\'intérêts sur cet article (l\'acte passe, et devra être confirmé par un second modérateur sous sept jours).')" wire:model="actConflict" />
                        <x-mary-checkbox :label="__('Acte pris sur injonction légale.')" wire:model="actLegal" />
                    </div>
                @endif

                <x-mary-button type="submit" :label="__('Enregistrer la suite donnée')" class="{{ $withdraws ? 'btn-error' : 'btn-primary' }}" spinner="applyAct" />
            </form>
        </div>
    </x-mary-card>
@endif

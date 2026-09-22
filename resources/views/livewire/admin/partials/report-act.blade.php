{{--
    Handling one report (SPEC 9.9), and the queue's own toggle.

    The moderation act lives here rather than one screen away: a report is
    read and acted on in the same move, or it is read and forgotten. The
    act still goes through ModerationService, so it lands in
    moderation_log with its numbered rule and its motive (SPEC 9.4).
--}}
@php($report = $this->actTarget())

<div class="mb-5 flex flex-wrap items-center gap-3 text-sm">
    <button type="button" class="btn btn-sm btn-ghost" wire:click="$toggle('showHandled')">
        {{ $this->handledLabel() }}
    </button>
    <span class="text-slate-500 dark:text-slate-400">
        {{ __('Signalements ouverts :') }} {{ $this->openCount() }}
    </span>
</div>

@if ($report !== null)
    <div class="card mb-5 border-accent-200 dark:border-accent-800">
        <div class="card-body space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="card-title">{{ __('Traiter le signalement') }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {{ $report->targetLabel() }}
                        <span class="badge ml-1">{{ $report->reason->label() }}</span>
                        <span class="badge ml-1">{{ $report->locale }}</span>
                    </p>
                </div>

                <button type="button" class="btn btn-sm btn-ghost" wire:click="closeAct">
                    {{ __('Fermer') }}
                </button>
            </div>

            @php($siblings = $this->siblingCount($report))
            @if ($siblings > 0)
                {{-- Only the first report of a target mails the team: the
                     others are here, and their number is the information
                     the mail could not carry. --}}
                <div class="alert alert-warning">
                    {{ __('Autres signalements ouverts sur ce même contenu : :count', ['count' => $siblings]) }}
                </div>
            @endif

            <div class="rounded-lg bg-slate-50 p-4 text-sm dark:bg-slate-800/60">
                <p class="whitespace-pre-line text-slate-700 dark:text-slate-200">{{ $report->body }}</p>
                <p class="mt-3 text-slate-500 dark:text-slate-400">
                    {{ __('Signalant :') }} {{ $report->reporter_email }}
                    @if ($report->targetUrl() !== null)
                        - <a class="link" href="{{ $report->targetUrl() }}" target="_blank" rel="noopener">{{ __('lire le contenu signalé') }}</a>
                    @endif
                </p>
            </div>

            <form wire:submit="applyAct" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="form-control">
                        <label class="label" for="report-act-kind">{{ __('Suite donnée') }}</label>
                        <select id="report-act-kind" class="input" wire:model.live="actKind">
                            @if ($report->article_id !== null)
                                <option value="hide">{{ __('Masquer l\'article et clore') }}</option>
                                <option value="delete">{{ __('Retirer l\'article et clore') }}</option>
                            @endif
                            <option value="actioned">{{ __('Acte pris ailleurs, clore') }}</option>
                            <option value="dismiss">{{ __('Classer sans suite') }}</option>
                        </select>
                        @error('actKind') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    @if (in_array($actKind, ['hide', 'delete'], true))
                        <div class="form-control">
                            <label class="label" for="report-act-rule">{{ __('Règle invoquée') }}</label>
                            <input id="report-act-rule" class="input @error('actRule') input-error @enderror" type="text" maxlength="20" wire:model="actRule" placeholder="R1, R2, ...">
                            <p class="field-hint">{{ __('Obligatoire : une sanction sans règle numérotée n\'est pas applicable.') }}</p>
                            @error('actRule') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>

                <div class="form-control">
                    <label class="label" for="report-act-motive">{{ __('Motif') }}</label>
                    <textarea id="report-act-motive" class="input @error('actMotive') input-error @enderror" rows="3" wire:model="actMotive"></textarea>
                    <p class="field-hint">{{ __('Lu par l\'auteur du contenu en cas de contestation, jamais par le signalant.') }}</p>
                    @error('actMotive') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                @if (in_array($actKind, ['hide', 'delete'], true))
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
                @endif

                <button type="submit" class="btn {{ in_array($actKind, ['hide', 'delete'], true) ? 'btn-danger' : 'btn-primary' }}">
                    {{ __('Enregistrer la suite donnée') }}
                </button>
            </form>
        </div>
    </div>
@endif

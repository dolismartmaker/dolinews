<div>
    <div class="mb-5">
        <h1 class="text-2xl font-semibold tracking-tight">{{ $heading }}</h1>
        {{-- No target delay is ever announced: committing volunteers' spare
             time would turn every hold-up into a breach. What is shown is the
             observed delay, which measures without promising (SPEC 9.5). --}}
        <p class="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">
            {{ __('Priorité aux annonces de sécurité, puis ancienneté. Ces chiffres sont publics et mesurent : ils ne promettent rien.') }}
        </p>
    </div>

    <div class="mb-5 grid gap-4 sm:grid-cols-2 lg:max-w-lg">
        <div class="stat">
            <div class="stat-value">{{ $medianSeconds !== null ? round($medianSeconds / 3600).' h' : '-' }}</div>
            <div class="stat-label">{{ __('Délai observé (médiane)') }}</div>
        </div>
        <div class="stat">
            <div class="stat-value">{{ $oldestPendingDays !== null ? $oldestPendingDays.' '.__('j') : '-' }}</div>
            <div class="stat-label">{{ __('Attente la plus ancienne') }}</div>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table-admin">
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th scope="col">{{ $column['label'] }}</th>
                        @endforeach
                        <th scope="col">{{ __('Auteur') }}</th>
                        <th scope="col" class="text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr wire:key="row-{{ $row->getKey() }}">
                            @foreach ($columns as $column)
                                <td @class(['font-medium text-slate-900 dark:text-white' => $column['key'] === 'title'])>
                                    {{ $this->formatCell($row, $column['key']) }}
                                </td>
                            @endforeach
                            <td>{{ $row->author?->display_name ?? $row->author?->name ?? '-' }}</td>
                            <td class="text-right whitespace-nowrap">
                                <a class="btn btn-sm btn-primary" href="{{ route('admin.review.show', $row) }}">{{ __('Relire') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) + 2 }}" class="px-4 py-10 text-center text-slate-500 dark:text-slate-400">
                                {{ __('File vide : aucune soumission en attente.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $rows->links() }}
    </div>
</div>

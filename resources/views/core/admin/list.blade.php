<div>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $heading }}</h1>
            @if ($intro !== '')
                <p class="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">{{ $intro }}</p>
            @endif
        </div>

        <div class="w-full sm:w-72">
            <label class="sr-only" for="list-search">{{ __('Rechercher') }}</label>
            <input
                id="list-search"
                class="input"
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Rechercher...') }}"
            >
        </div>
    </div>

    {{-- Action panel of the screen, when a row action opened one. --}}
    @if ($panelView !== null)
        @include($panelView)
    @endif

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table-admin">
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th scope="col">
                                @if ($column['sortable'])
                                    {{-- The sort control is a button and not a link:
                                         it acts on the component, it does not lead
                                         anywhere. --}}
                                    <button
                                        type="button"
                                        class="inline-flex cursor-pointer items-center gap-1 uppercase hover:text-slate-900 dark:hover:text-white"
                                        wire:click="sortBy('{{ $column['key'] }}')"
                                    >
                                        {{ $column['label'] }}
                                        @if ($sortField === $column['key'])
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $sortDir === 'asc' ? 'm4.5 15.75 7.5-7.5 7.5 7.5' : 'm19.5 8.25-7.5 7.5-7.5-7.5' }}" />
                                            </svg>
                                        @endif
                                    </button>
                                @else
                                    {{ $column['label'] }}
                                @endif
                            </th>
                        @endforeach
                        @if (! empty($actions))
                            <th scope="col" class="text-right">{{ __('Actions') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr wire:key="row-{{ $row->getKey() }}">
                            @foreach ($columns as $column)
                                <td>{{ $this->formatCell($row, $column['key']) }}</td>
                            @endforeach
                            @if (! empty($actions))
                                <td class="text-right whitespace-nowrap">
                                    @foreach ($actions as $action)
                                        <button
                                            type="button"
                                            wire:click="{{ $action['method'] }}({{ $row->getKey() }})"
                                            class="btn btn-sm {{ $action['class'] ?? 'btn-outline' }} ml-1"
                                        >
                                            {{ $action['label'] }}
                                        </button>
                                    @endforeach
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) + (empty($actions) ? 0 : 1) }}" class="px-4 py-10 text-center text-slate-500 dark:text-slate-400">
                                {{ $search === '' ? __('Aucun enregistrement.') : __('Aucun résultat pour cette recherche.') }}
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

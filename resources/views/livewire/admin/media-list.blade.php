<div>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $heading }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">{{ $intro }}</p>
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

    {{-- No overflow container here, unlike the generic table: the hover
         preview is positioned against its row, and an overflow parent would
         clip the very thing this screen exists for. --}}
    <div class="card">
        <table class="table-admin">
            <thead>
                <tr>
                    <th scope="col">{{ __('Aperçu') }}</th>
                    @foreach ($columns as $column)
                        <th scope="col">
                            @if ($column['sortable'])
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
                    <th scope="col">{{ __('Rattachement') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr wire:key="row-{{ $row->getKey() }}">
                        <td>
                            @include('partials.media-thumb', ['media' => $row, 'size' => 'sm'])
                        </td>

                        @foreach ($columns as $column)
                            <td @class(['break-all' => $column['key'] === 'path'])>
                                {{ $this->formatCell($row, $column['key']) }}
                            </td>
                        @endforeach

                        <td class="whitespace-nowrap">
                            @if ($row->article_id !== null)
                                <a class="link" href="{{ route('admin.review.show', $row->article_id) }}">
                                    {{ __('Article') }} #{{ $row->article_id }}
                                </a>
                            @else
                                {{-- An upload stays orphan until its article is
                                     created; a periodic task purges what was
                                     never bound (SPEC 5.2). --}}
                                <span class="badge badge-warning">{{ __('orphelin') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) + 2 }}" class="px-4 py-10 text-center text-slate-500 dark:text-slate-400">
                            {{ $search === '' ? __('Aucun média.') : __('Aucun résultat pour cette recherche.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $rows->links() }}
    </div>
</div>

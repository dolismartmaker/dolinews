<div>
    <div class="toolbar">
        <input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Rechercher..."
            aria-label="Rechercher"
        >
    </div>

    <table class="admin-table">
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th>
                        @if ($column['sortable'])
                            <a href="#" wire:click.prevent="sortBy('{{ $column['key'] }}')">
                                {{ $column['label'] }}
                                @if ($sortField === $column['key'])
                                    {{ $sortDir === 'asc' ? 'v' : '^' }}
                                @endif
                            </a>
                        @else
                            {{ $column['label'] }}
                        @endif
                    </th>
                @endforeach
                @if (! empty($actions))
                    <th>Actions</th>
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
                        <td class="row-actions">
                            @foreach ($actions as $action)
                                <button
                                    type="button"
                                    wire:click="{{ $action['method'] }}({{ $row->getKey() }})"
                                    class="row-action-btn {{ $action['class'] ?? '' }}"
                                >
                                    {{ $action['label'] }}
                                </button>
                            @endforeach
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columns) + (empty($actions) ? 0 : 1) }}">Aucun résultat.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">
        {{ $rows->links() }}
    </div>
</div>

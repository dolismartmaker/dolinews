<div>
    <x-mary-header :title="$heading" :subtitle="$intro !== '' ? $intro : null" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            <x-mary-input :placeholder="__('Rechercher...')" wire:model.live.debounce.300ms="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>
    </x-mary-header>

    {{-- Act panel of the screen, when a row action opened one. --}}
    @if ($panelView !== null)
        @include($panelView)
    @endif

    <x-admin::bulk-bar :count="count($selected)">
        <x-mary-button :label="__('admin::bulk.export')" wire:click="exportSelected" icon="o-arrow-down-tray" class="btn-sm" spinner />
    </x-admin::bulk-bar>

    <x-mary-card>
        <x-mary-table :headers="$headers" :rows="$rows" :sort-by="$sortBy" with-pagination
                      selectable wire:model.live="selected"
                      show-empty-text :empty-text="$search === '' ? __('Aucun enregistrement.') : __('Aucun résultat pour cette recherche.')">
            @if ($actions !== [])
                @scope('actions', $row, $actions)
                    <div class="flex justify-end gap-1 whitespace-nowrap">
                        @foreach ($actions as $action)
                            <x-mary-button :label="$action['label']" wire:click="{{ $action['method'] }}({{ $row['id'] }})"
                                           class="btn-sm {{ $action['class'] ?? 'btn-outline' }}" spinner />
                        @endforeach
                    </div>
                @endscope
            @endif
        </x-mary-table>
    </x-mary-card>
</div>

<div>
    <x-mary-header :title="$heading" :subtitle="$intro" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            <x-mary-input :placeholder="__('Rechercher...')" wire:model.live.debounce.300ms="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>
    </x-mary-header>

    <x-admin::bulk-bar :count="count($selected)">
        <x-mary-button :label="__('admin::bulk.export')" wire:click="exportSelected" icon="o-arrow-down-tray" class="btn-sm" spinner />
    </x-admin::bulk-bar>

    {{-- Moderation needs to SEE what was uploaded: the preview opens the
         full image on hover, and the thumbnail is also a link, because
         hovering does not exist on a touch screen. --}}
    <x-mary-card>
        <x-mary-table :headers="$headers" :rows="$rows" :sort-by="$sortBy" with-pagination
                      selectable wire:model.live="selected"
                      show-empty-text :empty-text="$search === '' ? __('Aucun média.') : __('Aucun résultat pour cette recherche.')">
            @scope('cell_preview', $media)
                <x-mary-popover>
                    <x-slot:trigger>
                        <a href="{{ $media->url() }}" target="_blank" rel="noopener noreferrer nofollow" class="block overflow-hidden rounded-field border border-base-300">
                            {{-- Sandboxed by construction: the file was
                                 re-encoded at intake and SVG is refused, so
                                 no image here can carry a script (D7). --}}
                            <img src="{{ $media->url() }}" alt="{{ $media->alt ?? '' }}" loading="lazy" class="h-14 w-20 object-cover">
                        </a>
                    </x-slot:trigger>
                    <x-slot:content>
                        <img src="{{ $media->url() }}" alt="" class="max-h-96 max-w-sm rounded-field object-contain">
                        <p class="mt-2 text-xs opacity-70">{{ $media->width }}x{{ $media->height }} - {{ $media->mime }}</p>
                    </x-slot:content>
                </x-mary-popover>
            @endscope
            @scope('cell_path', $media)
                <span class="break-all">{{ $media->path }}</span>
            @endscope
            @scope('cell_bytes', $media)
                {{ $this->formatCell($media, 'bytes') }}
            @endscope
            @scope('cell_created_at', $media)
                {{ $this->formatCell($media, 'created_at') }}
            @endscope
            @scope('cell_attachment', $media)
                @if ($media->article_id !== null)
                    <a class="link" href="{{ route('admin.review.show', $media->article_id) }}">{{ __('Article') }} #{{ $media->article_id }}</a>
                @else
                    {{-- An upload stays orphan until its article is created;
                         a periodic task purges what was never bound
                         (SPEC 5.2). --}}
                    <x-mary-badge :value="__('orphelin')" class="badge-warning badge-sm" />
                @endif
            @endscope
        </x-mary-table>
    </x-mary-card>
</div>

{{-- The links of a project sheet (SPEC 8), on the sheet itself and in
     the project column of an announcement. --}}
<ul class="-mx-2 mt-2 text-sm">
    @forelse ($links as $link)
        {{-- One row per link: its kind as a pictogram, what
             it is in the reader's words, then where it goes,
             so nobody clicks blind. A custom label hides the
             kind from sight, not from a screen reader. --}}
        <li>
            {{-- Outgoing links: nofollow ugc, no exception (D8). --}}
            <a class="group flex items-center gap-3 rounded-lg px-2 py-2 transition hover:bg-slate-100 dark:hover:bg-slate-800"
               href="{{ $link->url }}" rel="nofollow ugc">
                @include('partials.link-icon', ['type' => $link->type, 'class' => 'h-5 w-5 shrink-0 text-accent-700 dark:text-accent-300'])
                <span class="min-w-0 flex-1 font-medium text-accent-700 underline-offset-2 group-hover:underline dark:text-accent-300">
                    {{ $link->label ?? $link->type->label() }}
                    @if ($link->label !== null)
                        <span class="sr-only">({{ $link->type->label() }})</span>
                    @endif
                </span>
                @if ($link->is_broken)
                    <span class="badge badge-warning shrink-0">{{ __('lien cassé') }}</span>
                @endif
                @if ($link->host !== null)
                    <span class="min-w-0 max-w-[40%] truncate pl-2 text-right text-xs text-slate-500 dark:text-slate-400" title="{{ $link->host }}">{{ $link->host }}</span>
                @endif
            </a>
        </li>
    @empty
        <li class="px-2 text-slate-500 dark:text-slate-400">{{ __('Aucun lien déclaré.') }}</li>
    @endforelse
</ul>

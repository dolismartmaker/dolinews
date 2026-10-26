{{-- Structured filters of the feed in the airy skin: one line across the
     page from the large breakpoint, the four fields and the button side by
     side. Below it, folded under the same checkbox toggle as the default
     box, unfolded when a filter is already set (SPEC 6.1). --}}
@php($filtering = collect(['editor', 'project', 'dolibarr', 'focus'])->contains(fn (string $field): bool => request()->filled($field)))

<div class="border-b border-slate-100 py-6 dark:border-slate-800/70">
    <input type="checkbox" id="refine-toggle" class="peer sr-only" @checked($filtering)>
    <label for="refine-toggle" class="flex cursor-pointer items-center justify-between text-[15px] font-semibold peer-focus-visible:outline-2 peer-focus-visible:outline-accent-600 lg:hidden">
        {{ __('Affiner') }}
        <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
        </svg>
    </label>

    <form method="GET" action="{{ route('home') }}"
          class="hidden gap-6 pt-5 peer-checked:grid sm:grid-cols-2 lg:grid lg:grid-cols-[auto_repeat(4,minmax(0,1fr))_auto] lg:items-end lg:pt-0">
        <p class="hidden pb-2 text-[15px] font-semibold lg:block">{{ __('Affiner') }}</p>

        @include('partials.feed-refine-fields')

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="btn btn-sm btn-primary">{{ __('Filtrer') }}</button>
            @if (request()->query())
                <a class="btn btn-sm btn-ghost" href="{{ route('home') }}">{{ __('Tout afficher') }}</a>
            @endif
        </div>
    </form>
</div>

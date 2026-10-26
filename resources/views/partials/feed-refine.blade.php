{{-- Structured filters of the feed, in its side column (SPEC 6.1). --}}
@php($filtering = collect(['editor', 'project', 'dolibarr', 'focus'])->contains(fn (string $field): bool => request()->filled($field)))

{{-- Folded under a toggle below the large breakpoint, and the
     toggle is a checkbox rather than a details element: the
     public pages carry no JavaScript, and a details element
     cannot be held open by the stylesheet once the column has
     room for it. Unfolded when a filter is already set, so that
     the reader sees what narrows the feed. --}}
<div class="card">
    <input type="checkbox" id="refine-toggle" class="peer sr-only" @checked($filtering)>
    <label for="refine-toggle" class="flex cursor-pointer items-center justify-between px-5 py-3 text-sm font-semibold peer-focus-visible:outline-2 peer-focus-visible:outline-accent-600 lg:hidden">
        {{ __('Affiner') }}
        <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
        </svg>
    </label>

    <form method="GET" action="{{ route('home') }}" class="hidden px-5 pb-5 peer-checked:block lg:block lg:pt-5">
        <p class="mb-3 hidden text-sm font-semibold lg:block">{{ __('Affiner') }}</p>

        <div class="space-y-3">
            @include('partials.feed-refine-fields')
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <button type="submit" class="btn btn-sm btn-primary">{{ __('Filtrer') }}</button>
            @if (request()->query())
                <a class="btn btn-sm btn-ghost" href="{{ route('home') }}">{{ __('Tout afficher') }}</a>
            @endif
        </div>
    </form>
</div>

{{-- Free-text search of the feed, in the header of every public page: a
     reader on a sheet or an article who thinks of another module types it
     where they are, and lands on the feed filtered by it (SPEC 6.1).

     On the feed itself the structured filters already set ride along in
     hidden fields, so that a new search narrows what the reader was looking
     at instead of throwing their filters away. The filter box does the same
     for the search, in the other direction. --}}
@php($onFeed = request()->routeIs('home'))

<form method="GET" action="{{ route('home') }}" role="search"
      class="order-last w-full md:order-none md:w-auto md:max-w-2xl md:flex-1">
    <label class="sr-only" for="header-q">{{ __('Rechercher') }}</label>
    <div class="relative">
        <svg class="pointer-events-none absolute top-1/2 left-3 h-5 w-5 -translate-y-1/2 text-slate-400 dark:text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
        </svg>
        <input class="header-search-input" type="search" id="header-q" name="q"
               value="{{ $onFeed ? request()->string('q') : '' }}"
               maxlength="100" placeholder="{{ __('un module, une fonction, un mot de l\'annonce') }}">
    </div>

    @if ($onFeed)
        @foreach (['editor', 'project', 'dolibarr', 'focus'] as $field)
            @if (request()->filled($field))
                <input type="hidden" name="{{ $field }}" value="{{ request()->string($field) }}">
            @endif
        @endforeach
        @foreach ((array) request()->input('maturity', []) as $maturity)
            @if (is_string($maturity))
                <input type="hidden" name="maturity[]" value="{{ $maturity }}">
            @endif
        @endforeach
    @endif
</form>

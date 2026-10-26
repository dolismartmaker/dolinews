@extends('layouts.public')

{{-- The page title says what the feed holds rather than what the site
     calls it: "Le fil" names a section to whoever is already here, and
     nothing to whoever reads it in a list of search results. --}}
@section('title', __('Annonces de l\'écosystème Dolibarr'))

@push('head')
    @include('partials.json-ld', ['data' => $structuredData])
@endpush

@section('content')
    {{-- The feed in the main column, and what is not the feed stands aside
         in a column of its own: the presentation of the service and the
         structured filters, the free-text search having moved up to the
         header. Below the large breakpoint the column comes first, so
         that the two delivery channels are still named at the head of the
         feed (SPEC 6.4) and not under twenty-five cards. --}}
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <aside class="home-aside space-y-4 lg:col-start-2 lg:row-start-1">
            @include('partials.feed-intro')

            @include('partials.feed-refine')
        </aside>

        <div class="min-w-0 lg:col-start-1 lg:row-start-1">
            @include('partials.feed-matching-projects')

            <div class="feed-list">
                @forelse ($groups as $group)
                    @include('partials.feed-entry', ['group' => $group])
                @empty
                    @include('partials.feed-empty')
                @endforelse
            </div>

            <div class="mt-6">
                {{ $articles->links() }}
            </div>
        </div>
    </div>
@endsection

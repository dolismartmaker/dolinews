@extends('layouts.public')

{{-- Feed page of the airy skin: one column at full width, with no side
     column. The presentation comes first, across the page, so that both
     delivery channels are named at the head of the feed at every width
     (SPEC 6.4); the filters follow on one line, then the feed (SPEC 6.6). --}}
@section('title', __('Annonces de l\'écosystème Dolibarr'))

@push('head')
    @include('partials.json-ld', ['data' => $structuredData])
@endpush

@section('content')
    @include('partials.feed-intro')

    @include('partials.feed-refine')

    <div class="mt-12">
        @include('partials.feed-matching-projects')

        <div class="feed-list">
            @forelse ($groups as $group)
                @include('partials.feed-entry', ['group' => $group])
            @empty
                @include('partials.feed-empty')
            @endforelse
        </div>

        <div class="mt-10">
            {{ $articles->links() }}
        </div>
    </div>
@endsection

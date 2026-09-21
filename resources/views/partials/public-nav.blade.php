{{--
    Primary navigation of the public site, rendered twice by the layout: as a
    bar from the medium breakpoint, and inside the disclosure menu below it.
    One list for both, so an entry can never exist in one and not the other.

    $mode: 'bar' or 'stack'.
--}}
@php
    $navItems = [
        ['route' => 'home', 'match' => 'home', 'label' => __('Le fil')],
        ['route' => 'pages.editor-guide', 'match' => 'pages.editor-guide', 'label' => __('Publier')],
        ['route' => 'review.info', 'match' => 'review.info', 'label' => __('La revue')],
        ['route' => 'pages.commitments', 'match' => 'pages.commitments', 'label' => __('Engagements')],
        ['route' => 'pages.rules', 'match' => 'pages.rules', 'label' => __('Règles')],
        ['route' => 'pages.api', 'match' => 'pages.api', 'label' => __('API')],
    ];

    foreach ($navItems as $index => $item) {
        $navItems[$index]['active'] = request()->routeIs($item['match']);
    }
@endphp

@if (($mode ?? 'bar') === 'bar')
    @foreach ($navItems as $item)
        <a href="{{ route($item['route']) }}"
           @if ($item['active']) aria-current="page" @endif
           class="rounded-lg px-3 py-2 text-sm font-medium transition {{ $item['active'] ? 'bg-slate-100 text-slate-900 dark:bg-slate-800 dark:text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white' }}">
            {{ $item['label'] }}
        </a>
    @endforeach
@else
    @foreach ($navItems as $item)
        <a href="{{ route($item['route']) }}"
           @if ($item['active']) aria-current="page" @endif
           class="block rounded-lg px-3 py-2 text-base font-medium {{ $item['active'] ? 'bg-slate-100 text-slate-900 dark:bg-slate-800 dark:text-white' : 'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800' }}">
            {{ $item['label'] }}
        </a>
    @endforeach
@endif

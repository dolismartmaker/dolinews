{{-- Navigation of the account area, in the grammar of the back-office
     sidebar: a column from the large breakpoint, a scrollable row below it.

     The screens used to be reachable only from a row of buttons at the
     bottom of one of them, so an author landing on the token page had no way
     back to the others. --}}
@php
    $tabs = [
        [
            'route' => 'account.show',
            'match' => 'account.show',
            'label' => __('Mon compte'),
            'icon' => 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
        ],
        [
            'route' => 'account.contribute',
            'match' => 'account.contribute*',
            'label' => __('Contributeur'),
            'icon' => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        ],
        [
            'route' => 'account.projects',
            'match' => 'account.projects*',
            'label' => __('Mes projets'),
            'icon' => 'm21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
        ],
        [
            'route' => 'account.articles',
            'match' => 'account.articles*',
            'label' => __('Mes articles'),
            'icon' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
        ],
        [
            'route' => 'account.translations',
            'match' => 'account.translations*',
            'label' => __('Traductions'),
            'icon' => 'm10.5 21 5.25-11.25L21 21m-9-3h7.5M3 5.621a48.474 48.474 0 0 1 6-.371m0 0c1.12 0 2.233.038 3.334.114M9 5.25V3m3.334 2.364C11.176 10.658 7.69 15.08 3 17.502m9.334-12.138c.896.061 1.785.147 2.666.257m-4.589 8.495a18.023 18.023 0 0 1-3.827-5.802',
        ],
        [
            'route' => 'account.tokens',
            'match' => 'account.tokens*',
            'label' => __('Jetons d\'API'),
            'icon' => 'M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z',
        ],
        [
            'route' => 'account.password',
            'match' => 'account.password*',
            'label' => __('Mot de passe'),
            'icon' => 'M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z',
        ],
    ];

    foreach ($tabs as $index => $tab) {
        $tabs[$index]['active'] = request()->routeIs($tab['match']);
    }

    $current = collect($tabs)->firstWhere('active', true) ?? $tabs[0];
@endphp

{{-- Below the large breakpoint, a disclosure menu naming the current screen
     rather than a scrolling row of tabs: on a 390 px screen the active entry
     of such a row ends up off-screen, and the reader loses track of where
     they are. A details element, so it needs no script. --}}
<details class="relative lg:hidden">
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium dark:border-slate-700 dark:bg-slate-900">
        <span class="flex min-w-0 items-center gap-3">
            <svg class="h-5 w-5 shrink-0 text-accent-700 dark:text-accent-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $current['icon'] }}" />
            </svg>
            <span class="truncate">{{ $current['label'] }}</span>
        </span>
        <svg class="h-3 w-3 shrink-0" viewBox="0 0 12 12" aria-hidden="true">
            <path d="M2 4.5 6 8.5 10 4.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </summary>

    <nav class="absolute z-40 mt-2 w-full space-y-1 rounded-xl border border-slate-200 bg-white p-1 shadow-lg dark:border-slate-700 dark:bg-slate-900"
         aria-label="{{ __('Mon compte') }}">
        @foreach ($tabs as $tab)
            <a href="{{ route($tab['route']) }}"
               @if ($tab['active']) aria-current="page" @endif
               class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium {{ $tab['active'] ? 'bg-accent-50 text-accent-900 dark:bg-accent-950 dark:text-accent-100' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $tab['icon'] }}" />
                </svg>
                <span class="truncate">{{ $tab['label'] }}</span>
            </a>
        @endforeach
    </nav>
</details>

<nav class="hidden lg:flex lg:flex-col lg:gap-1" aria-label="{{ __('Mon compte') }}">
    @foreach ($tabs as $tab)
        <a href="{{ route($tab['route']) }}"
           @if ($tab['active']) aria-current="page" @endif
           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ $tab['active'] ? 'bg-accent-50 text-accent-900 dark:bg-accent-950 dark:text-accent-100' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white' }}">
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $tab['icon'] }}" />
            </svg>
            <span class="truncate">{{ $tab['label'] }}</span>
        </a>
    @endforeach
</nav>

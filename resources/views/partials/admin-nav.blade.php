{{--
    Single navigation partial, rendered by both the desktop sidebar and the
    mobile bottom bar, driven by $mode. Writing it twice is how the two drift
    apart: an entry added to one and forgotten in the other.

    The active state resolves with routeIs(). "admin.review" is deliberately a
    prefix of "admin.review.show" so that reading a submission keeps the queue
    entry lit -- it is the same place, one level down.

    The bottom bar carries the entries flagged 'bottom' and no more than five:
    beyond that it scrolls sideways on a 360 px screen and stops being usable.
--}}
@php
    $navGroups = [
        [
            'label' => null,
            'items' => [
                [
                    'route' => 'admin.dashboard',
                    'label' => __('Tableau de bord'),
                    'bottom' => true,
                    'icon' => 'm2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
                ],
                [
                    'route' => 'admin.review',
                    'match' => 'admin.review*',
                    'label' => __('File de revue'),
                    'bottom' => true,
                    'icon' => 'M9 3.75H6.912a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H15M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859M12 3v8.25m0 0-3-3m3 3 3-3',
                ],
            ],
        ],
        [
            'label' => __('Contenus'),
            'items' => [
                [
                    'route' => 'admin.articles',
                    'match' => 'admin.articles*',
                    'label' => __('Articles'),
                    'bottom' => true,
                    'icon' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
                ],
                [
                    'route' => 'admin.projects',
                    'match' => 'admin.projects*',
                    'label' => __('Fiches projet'),
                    'bottom' => true,
                    'icon' => 'm21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
                ],
                [
                    'route' => 'admin.editors',
                    'match' => 'admin.editors*',
                    'label' => __('Éditeurs'),
                    'bottom' => true,
                    'icon' => 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
                ],
                [
                    'route' => 'admin.media',
                    'match' => 'admin.media*',
                    'label' => __('Médias'),
                    'icon' => 'm2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z',
                ],
            ],
        ],
        [
            'label' => __('Le service'),
            'items' => [
                [
                    'route' => 'admin.users',
                    'match' => 'admin.users*',
                    'label' => __('Comptes'),
                    'icon' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
                ],
                [
                    'route' => 'admin.reports',
                    'match' => 'admin.reports*',
                    'label' => __('Signalements'),
                    'icon' => 'M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5',
                ],
                [
                    'route' => 'admin.moderation',
                    'match' => 'admin.moderation*',
                    'label' => __('Journal de modération'),
                    'icon' => 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z',
                ],
                [
                    'route' => 'admin.api-requests',
                    'match' => 'admin.api-requests*',
                    'label' => __('Appels API'),
                    'icon' => 'M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 0 1 1.06 0Z',
                ],
            ],
        ],
    ];

    // The active state is resolved here rather than inside the loops below, and
    // this block is the only PHP of the file: Blade takes everything between an
    // inline php directive and the next closing one as raw PHP, so a single
    // inline form inside a loop silently stops compiling the rest of the file.
    foreach ($navGroups as $groupIndex => $group) {
        foreach ($group['items'] as $itemIndex => $item) {
            $navGroups[$groupIndex]['items'][$itemIndex]['active'] = request()
                ->routeIs($item['match'] ?? $item['route'].'*');
        }
    }

    $bottomItems = collect($navGroups)
        ->flatMap(fn (array $group): array => $group['items'])
        ->filter(fn (array $item): bool => $item['bottom'] ?? false)
        ->take(5);
@endphp

@if (($mode ?? 'sidebar') === 'sidebar')
    @foreach ($navGroups as $group)
        @if ($group['label'] !== null)
            <p class="mt-6 mb-1 px-3 text-xs font-semibold tracking-wider text-slate-400 uppercase dark:text-slate-500">
                {{ $group['label'] }}
            </p>
        @endif

        <nav class="space-y-1">
            @foreach ($group['items'] as $item)
                <a href="{{ route($item['route']) }}"
                   @if ($item['active']) aria-current="page" @endif
                   class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ $item['active'] ? 'bg-accent-50 text-accent-900 dark:bg-accent-950 dark:text-accent-100' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white' }}">
                    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" />
                    </svg>
                    <span class="truncate">{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>
    @endforeach

    {{-- The public site is one click away: a moderator checks what a reader
         actually sees after publishing, and would otherwise retype the URL. --}}
    <nav class="mt-6 space-y-1 border-t border-slate-200 pt-4 dark:border-slate-800">
        <a href="{{ route('home') }}"
           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0 0a8.949 8.949 0 0 0 4.951-1.488A3.987 3.987 0 0 0 13 16h-2a3.987 3.987 0 0 0-3.951 3.512A8.949 8.949 0 0 0 12 21Zm3-11.25a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <span class="truncate">{{ __('Voir le fil public') }}</span>
        </a>
    </nav>
@else
    @foreach ($bottomItems as $item)
        <a href="{{ route($item['route']) }}"
           @if ($item['active']) aria-current="page" @endif
           class="flex flex-1 flex-col items-center gap-1 px-2 py-2 text-center text-[0.7rem] font-medium {{ $item['active'] ? 'text-accent-700 dark:text-accent-300' : 'text-slate-500 dark:text-slate-400' }}">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" />
            </svg>
            <span class="w-full truncate">{{ $item['label'] }}</span>
        </a>
    @endforeach
@endif

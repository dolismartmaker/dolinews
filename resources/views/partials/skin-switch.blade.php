{{-- Skin switch. A details/summary menu of plain links, like the theme and
     language ones: the public pages carry no JavaScript, so the choice is
     made by following a link and lands in the session.

     Absent when the instance closes the choice or offers a single skin: a
     menu of one entry offers nothing. --}}
@if (\App\Support\Skins::visitorChoice())
    @php
        $skinLabels = [
            'default' => __('Cartes'),
            'compact' => __('Liste compacte'),
            'airy' => __('Aéré'),
        ];
        $currentSkin = $skinChoice ?? \App\Support\Skins::DEFAULT;
    @endphp

    <nav aria-label="{{ __('Habillage de l\'interface') }}">
        <details class="relative">
            <summary class="flex cursor-pointer list-none items-center gap-1.5 rounded-lg px-2.5 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true" focusable="false">
                    <rect x="3.5" y="4" width="17" height="16" rx="1.5"/>
                    <path d="M3.5 9h17M9 9v11" stroke-linecap="round"/>
                </svg>
                <span class="sr-only sm:not-sr-only">{{ $skinLabels[$currentSkin] ?? $currentSkin }}</span>
                <svg class="h-3 w-3" viewBox="0 0 12 12" aria-hidden="true" focusable="false">
                    <path d="M2 4.5 6 8.5 10 4.5" fill="none" stroke="currentColor" stroke-width="1.6"
                          stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </summary>

            <ul class="absolute right-0 z-50 mt-2 w-48 rounded-xl border border-slate-200 bg-white p-1 shadow-lg dark:border-slate-700 dark:bg-slate-900">
                @foreach (\App\Support\Skins::offered() as $code)
                    <li>
                        <a href="{{ route('skin.switch', ['skin' => $code]) }}"
                           @if ($code === $currentSkin) aria-current="true" @endif
                           class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm {{ $code === $currentSkin ? 'font-medium text-slate-900 dark:text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                            <span class="w-3 text-accent-600 dark:text-accent-400" aria-hidden="true">@if ($code === $currentSkin)&#10003;@endif</span>
                            {{ $skinLabels[$code] ?? $code }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </details>
    </nav>
@endif

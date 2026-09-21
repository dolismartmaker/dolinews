{{-- Theme switch. A details/summary menu of plain links, like the language
     one: the public pages carry no JavaScript, so the choice is made by
     following a link and lands in the session.

     Three entries and not a toggle: "Système" is a state of its own, and a
     reader who picked it must be able to come back to it after trying the
     other two. --}}
@php
    $themes = [
        'auto' => __('Système'),
        'light' => __('Clair'),
        'dark' => __('Sombre'),
    ];
@endphp

<nav aria-label="{{ __('Thème de l\'interface') }}">
    <details class="relative">
        <summary class="flex cursor-pointer list-none items-center gap-1.5 rounded-lg px-2.5 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">
            {{-- Inline SVG, no icon font: one stylesheet, no extra request,
                 and it inherits the text colour. --}}
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="4.5"/>
                <path d="M12 2v2.5M12 19.5V22M4.2 4.2l1.8 1.8M18 18l1.8 1.8M2 12h2.5M19.5 12H22M4.2 19.8 6 18M18 6l1.8-1.8" stroke-linecap="round"/>
            </svg>
            <span class="sr-only sm:not-sr-only">{{ $themes[$themeChoice] ?? $themes['auto'] }}</span>
            <svg class="h-3 w-3" viewBox="0 0 12 12" aria-hidden="true" focusable="false">
                <path d="M2 4.5 6 8.5 10 4.5" fill="none" stroke="currentColor" stroke-width="1.6"
                      stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </summary>

        <ul class="absolute right-0 z-50 mt-2 w-44 rounded-xl border border-slate-200 bg-white p-1 shadow-lg dark:border-slate-700 dark:bg-slate-900">
            @foreach ($themes as $code => $label)
                <li>
                    <a href="{{ route('theme.switch', ['theme' => $code]) }}"
                       @if ($code === $themeChoice) aria-current="true" @endif
                       class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm {{ $code === $themeChoice ? 'font-medium text-slate-900 dark:text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                        <span class="w-3 text-accent-600 dark:text-accent-400" aria-hidden="true">@if ($code === $themeChoice)&#10003;@endif</span>
                        {{ $label }}
                    </a>
                </li>
            @endforeach
        </ul>
    </details>
</nav>

{{-- Interface language switch (D14). A details/summary menu and never a
     <select>: the public pages carry no JavaScript, and a select with no
     onchange handler is a control that does nothing. The same absence of
     JavaScript is why the menu closes on its own button rather than on a
     click outside it.

     Every entry is a plain link, so a language is reachable without ever
     opening the menu, and each one carries its endonym: a reader looking
     for English is not asked to know the French word for it. --}}
@php
    $locales = (array) config('dolinews.locales', ['fr']);
    $current = app()->getLocale();
@endphp

<nav aria-label="{{ __('Langue de l\'interface') }}">
    <details class="relative">
        <summary class="flex cursor-pointer list-none items-center gap-1.5 rounded-lg px-2.5 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">
            {{-- Inline SVG rather than an icon font: one stylesheet, no
                 extra request, and it inherits the text colour. --}}
            <svg class="h-4 w-4" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.6"/>
                <path d="M3 12h18M12 3c2.5 2.6 2.5 15.4 0 18M12 3c-2.5 2.6-2.5 15.4 0 18"
                      fill="none" stroke="currentColor" stroke-width="1.6"/>
            </svg>
            <span>{{ config('dolinews.locale_names.'.$current, strtoupper($current)) }}</span>
            <svg class="h-3 w-3" viewBox="0 0 12 12" aria-hidden="true" focusable="false">
                <path d="M2 4.5 6 8.5 10 4.5" fill="none" stroke="currentColor" stroke-width="1.6"
                      stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </summary>

        {{-- Scrollable past ten languages: the list grows with the
             offered set and a short viewport would otherwise cut the
             last entries off with no way to reach them. --}}
        <ul class="absolute right-0 z-50 mt-2 max-h-80 w-44 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-lg dark:border-slate-700 dark:bg-slate-900">
            @foreach ($locales as $code)
                <li>
                    <a href="{{ route('locale.switch', ['locale' => $code]) }}"
                       hreflang="{{ $code }}"
                       lang="{{ $code }}"
                       @if ($code === $current) aria-current="true" @endif
                       class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm {{ $code === $current ? 'font-medium text-slate-900 dark:text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                        <span class="w-3 text-accent-600 dark:text-accent-400" aria-hidden="true">@if ($code === $current)&#10003;@endif</span>
                        {{ config('dolinews.locale_names.'.$code, strtoupper($code)) }}
                    </a>
                </li>
            @endforeach
        </ul>
    </details>
</nav>

{{-- Interface language switch (D14). A details/summary menu and never a
     <select>: the public pages carry no JavaScript, and a select with no
     onchange handler is a control that does nothing. The same absence of
     JavaScript is why the menu closes on its own button rather than on a
     click outside it.

     Every entry is a plain link, so a language is reachable without ever
     opening the menu, and each one carries its endonym: a reader looking
     for English is not asked to know the French word for it. --}}
@php($locales = (array) config('dolinews.locales', ['fr']))
@php($current = app()->getLocale())

<nav class="locale-switch" aria-label="{{ __('Langue de l\'interface') }}">
    <details>
        <summary>
            {{-- Inline SVG rather than an icon font: one stylesheet, no
                 extra request, and it inherits the text colour. --}}
            <svg class="locale-globe" viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.6"/>
                <path d="M3 12h18M12 3c2.5 2.6 2.5 15.4 0 18M12 3c-2.5 2.6-2.5 15.4 0 18"
                      fill="none" stroke="currentColor" stroke-width="1.6"/>
            </svg>
            <span>{{ config('dolinews.locale_names.'.$current, strtoupper($current)) }}</span>
            <svg class="locale-caret" viewBox="0 0 12 12" width="10" height="10" aria-hidden="true" focusable="false">
                <path d="M2 4.5 6 8.5 10 4.5" fill="none" stroke="currentColor" stroke-width="1.6"
                      stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </summary>
        <ul>
            @foreach ($locales as $code)
                @php($name = config('dolinews.locale_names.'.$code, strtoupper($code)))
                <li>
                    <a href="{{ route('locale.switch', ['locale' => $code]) }}"
                       hreflang="{{ $code }}"
                       lang="{{ $code }}"
                       @if ($code === $current) aria-current="true" @endif>
                        <span class="locale-mark" aria-hidden="true">@if ($code === $current)&#10003;@endif</span>
                        {{ $name }}
                    </a>
                </li>
            @endforeach
        </ul>
    </details>
</nav>

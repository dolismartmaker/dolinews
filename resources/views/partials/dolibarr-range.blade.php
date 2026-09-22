{{-- Dolibarr majors an announcement concerns, as one short field of the meta
     line. The pictogram carries "version", so the phrase does not repeat it:
     "Version" on that same line is the version of the module, and the two are
     not the same figure.

     Nothing is said of what a module supports today (SPEC D1): this is the
     range its announcement carried, on its date, and the date sits next to it.

     The floor comes from announcedDolibarrMin(), which drops the module
     builder's default: a bound nobody typed is not an announcement.

     Parameter: $article. --}}
@php($min = $article->announcedDolibarrMin())
@php($max = $article->dolibarr_max)

@if ($min !== null || $max !== null)
    <span class="inline-flex items-center gap-1">
        <svg class="h-3.5 w-3.5 shrink-0 text-slate-400 dark:text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
        </svg>
        @if ($min !== null && $max !== null)
            {{ __('Dolibarr :min à :max', ['min' => $min, 'max' => $max]) }}
        @elseif ($min !== null)
            {{ __('Dolibarr :min et supérieur', ['min' => $min]) }}
        @else
            {{ __('Dolibarr jusqu\'à :max', ['max' => $max]) }}
        @endif
    </span>
@endif

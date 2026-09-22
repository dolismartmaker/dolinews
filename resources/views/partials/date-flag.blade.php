{{-- Publication date as a flag pinned to the right of a listed announcement.
     The day stands alone and the month follows abbreviated: a feed is read by
     scanning down that column, and four digits of year on every line drown the
     one figure that separates two entries.

     The month is formatted through Carbon's own locale rather than the
     application's date helpers: isoFormat carries its abbreviations for the ten
     offered locales, and the machine-readable value stays in the datetime
     attribute whatever the reader's language.

     Parameters: $date (CarbonInterface|null), $compact (bool, default false). --}}
@php($compact = $compact ?? false)

@if ($date !== null)
    <p class="date-flag {{ $compact ? 'date-flag-sm' : '' }}">
        <time datetime="{{ $date->toDateString() }}">
            <span class="date-flag-day">{{ $date->format('j') }}</span>
            <span class="date-flag-rest">
                {{ $date->locale(app()->getLocale())->isoFormat('MMM') }}<br>{{ $date->format('Y') }}
            </span>
        </time>
    </p>
@endif

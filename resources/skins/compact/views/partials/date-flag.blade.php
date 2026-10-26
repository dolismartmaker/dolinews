{{-- Publication date in the compact skin: plain text in a fixed-width
     face, where the default skin draws a flag. Same machine-readable value
     in the datetime attribute.

     Parameters: $date (CarbonInterface|null), $compact (bool, unused here:
     the plain form is already the small one). --}}
@if ($date !== null)
    <p class="date-plain">
        <time datetime="{{ $date->toDateString() }}">{{ $date->locale(app()->getLocale())->isoFormat('D MMM YYYY') }}</time>
    </p>
@endif

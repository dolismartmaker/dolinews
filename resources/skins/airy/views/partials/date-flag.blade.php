{{-- Publication date in the airy skin: small capitals in soft colour, where
     the default skin draws a flag. Same machine-readable value in the
     datetime attribute.

     Parameters: $date (CarbonInterface|null), $compact (bool, unused here:
     the type is already small). --}}
@if ($date !== null)
    <p class="date-airy">
        <time datetime="{{ $date->toDateString() }}">{{ $date->locale(app()->getLocale())->isoFormat('D MMMM YYYY') }}</time>
    </p>
@endif

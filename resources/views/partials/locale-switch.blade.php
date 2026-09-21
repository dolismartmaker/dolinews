{{-- Interface language switch (D14). Plain links and never a <select>:
     the public pages carry no JavaScript, and a select with no onchange
     handler is a control that does nothing. --}}
<nav class="locale-switch" aria-label="{{ __('Langue de l\'interface') }}">
    @foreach ((array) config('dolinews.locales', ['fr']) as $code)
        @php($name = config('dolinews.locale_names.'.$code, strtoupper($code)))
        @if ($code === app()->getLocale())
            <span aria-current="true">{{ $name }}</span>
        @else
            <a href="{{ route('locale.switch', ['locale' => $code]) }}" hreflang="{{ $code }}">{{ $name }}</a>
        @endif
    @endforeach
</nav>

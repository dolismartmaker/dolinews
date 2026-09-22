{{-- What a page says about itself to whoever is not reading it: a search
     engine, a feed reader, a forum rendering a shared link.

     The values arrive escaped, section and fallback alike: Laravel runs
     e() over the inline form of @section and over the default handed to
     yieldContent. They are therefore printed raw, and escaping them
     again here would show a reader the entity rather than the letter.
     The titles that reach this head are written by third parties
     (SPEC 5.1): one quote in a module name would otherwise end an
     attribute. --}}
@php
    $metaTitle = trim($__env->yieldContent('title', $defaultTitle ?? __('Le fil')));
    $metaDescription = trim($__env->yieldContent(
        'description',
        __('Annonces de l\'écosystème Dolibarr : ce qui a été annoncé, et quand.'),
    ));
    $metaCanonical = $canonicalUrl ?? \App\Domain\Dolinews\Seo\CanonicalUrl::for(request());
    $metaImage = \App\Domain\Dolinews\Seo\PageImage::for($ogImage ?? null);
    $metaLocale = \App\Domain\Dolinews\Seo\PageLocale::full($ogLocale ?? app()->getLocale());
    // Empty on a page whose address carries no language, and on an
    // announcement, whose versions are its translations rather than its
    // interface locales - those are pushed by the article view itself.
    $metaAlternates = ($alternates ?? null) === null
        ? \App\Domain\Dolinews\Seo\LocalizedUrls::for(request())
        : [];
@endphp
<title>{!! $metaTitle !!} - DoliNews</title>
<meta name="description" content="{!! $metaDescription !!}">
@if ($noindex ?? false)
    <meta name="robots" content="noindex">
@endif
<link rel="canonical" href="{{ $metaCanonical }}">

{{-- The same page in the other nine languages, and the bare root as the
     default: it is the address that names no language and picks one
     (SPEC 6.5), which is exactly what x-default is for. --}}
@foreach ($metaAlternates as $code => $href)
    <link rel="alternate" hreflang="{{ $code }}" href="{{ $href }}">
@endforeach
@if ($metaAlternates !== [])
    <link rel="alternate" hreflang="x-default" href="{{ route('root') }}">
@endif

<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

{{-- The sharing card. Without it, the announcement of a security fix
     travels through a forum or a chat room as a bare URL, which is
     exactly the announcement that has to be opened. --}}
<meta property="og:site_name" content="DoliNews">
<meta property="og:type" content="{{ $ogType ?? 'website' }}">
<meta property="og:title" content="{!! $metaTitle !!}">
<meta property="og:description" content="{!! $metaDescription !!}">
<meta property="og:url" content="{{ $metaCanonical }}">
<meta property="og:locale" content="{{ $metaLocale }}">
<meta property="og:image" content="{{ $metaImage }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{!! $metaTitle !!}">
<meta name="twitter:description" content="{!! $metaDescription !!}">
<meta name="twitter:image" content="{{ $metaImage }}">

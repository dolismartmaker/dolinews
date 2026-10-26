<!DOCTYPE html>
{{-- The theme class drives the dark variant of app.css: "dark" when the
     visitor asked for it, "theme-auto" when they follow their system, and
     nothing at all when they asked for light. data-skin names the skin
     serving the page (SetSkin). --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $themeClass ?? 'theme-auto' }}" data-skin="{{ $skinChoice ?? 'default' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.head-meta')
    {{-- One stylesheet, the skin's, and no JavaScript entry: the Vite input
         carries CSS only, and a test locks the absence of a bundle in
         (capdoc:LARAVEL_PAGES_PUBLIQUES.md). --}}
    @vite([$skinStylesheet ?? 'resources/css/app.css'])
    <link rel="alternate" type="application/rss+xml" title="DoliNews" href="{{ route('feeds.rss', ['locale' => app()->getLocale()]) }}">
    <link rel="alternate" type="application/json" title="DoliNews" href="{{ route('feeds.json', ['locale' => app()->getLocale()]) }}">
    {{-- Language versions of one announcement, and the schema.org
         description of what the page holds: both are page-specific, and
         both belong to the head. --}}
    @stack('head')
</head>
<body class="flex min-h-screen flex-col bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    {{-- Most of an impersonation is spent here, out of reach of the
         back-office menu: the banner carries the only way back. --}}
    @include('admin::partials.impersonation-banner')
    <a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:shadow dark:focus:bg-slate-900">
        {{ __('Aller au contenu') }}
    </a>

    @include('partials.site-header')

    <main id="content" class="mx-auto w-full max-w-6xl flex-1 px-4 py-8">
        @include('partials.flash-messages')

        @yield('content')
    </main>

    @include('partials.site-footer')
</body>
</html>

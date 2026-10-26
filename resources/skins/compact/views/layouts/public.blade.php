<!DOCTYPE html>
{{-- Public layout of the compact skin: a one-line masthead, the page on a
     plain background, a footer of a few lines. Same head as the default
     layout, the stylesheet being the skin's. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $themeClass ?? 'theme-auto' }}" data-skin="{{ $skinChoice ?? 'compact' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.head-meta')
    {{-- One stylesheet and no JavaScript entry (capdoc:LARAVEL_PAGES_PUBLIQUES.md). --}}
    @vite([$skinStylesheet ?? 'resources/skins/compact/skin.css'])
    <link rel="alternate" type="application/rss+xml" title="DoliNews" href="{{ route('feeds.rss', ['locale' => app()->getLocale()]) }}">
    <link rel="alternate" type="application/json" title="DoliNews" href="{{ route('feeds.json', ['locale' => app()->getLocale()]) }}">
    @stack('head')
</head>
<body class="flex min-h-screen flex-col bg-white text-sm text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    @include('admin::partials.impersonation-banner')
    <a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:bg-white focus:px-4 focus:py-2 dark:focus:bg-slate-900">
        {{ __('Aller au contenu') }}
    </a>

    @include('partials.site-header')

    <main id="content" class="mx-auto w-full max-w-6xl flex-1 px-4 py-4">
        @include('partials.flash-messages')

        @yield('content')
    </main>

    @include('partials.site-footer')
</body>
</html>

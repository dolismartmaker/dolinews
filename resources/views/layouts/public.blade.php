<!DOCTYPE html>
{{-- The theme class drives the dark variant of app.css: "dark" when the
     visitor asked for it, "theme-auto" when they follow their system, and
     nothing at all when they asked for light. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $themeClass ?? 'theme-auto' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('Le fil')) - DoliNews</title>
    <meta name="description" content="@yield('description', __('Annonces de l\'écosystème Dolibarr : ce qui a été annoncé, et quand.'))">
    {{-- One stylesheet and no JavaScript entry: the Vite input carries CSS
         only, and a test locks the absence of a bundle in
         (~/docs/laravel/LARAVEL_PAGES_PUBLIQUES.md). --}}
    @vite(['resources/css/app.css'])
    <link rel="alternate" type="application/rss+xml" title="DoliNews" href="{{ route('feeds.rss') }}">
    <link rel="alternate" type="application/json" title="DoliNews" href="{{ route('feeds.json') }}">
</head>
<body class="flex min-h-screen flex-col bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:shadow dark:focus:bg-slate-900">
        {{ __('Aller au contenu') }}
    </a>

    {{-- Opaque background and no backdrop filter: a translucent sticky bar is
         repainted on every scroll step, for nothing (LARAVEL_PAGES_PUBLIQUES 3). --}}
    <header class="print-hidden sticky top-0 z-40 border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="mx-auto flex h-16 max-w-6xl items-center gap-2 px-4">
            <a href="{{ route('home') }}" class="mr-2 shrink-0 text-lg font-semibold tracking-tight">
                DoliNews
            </a>

            <nav class="hidden items-center gap-1 md:flex" aria-label="{{ __('Navigation principale') }}">
                @include('partials.public-nav', ['mode' => 'bar'])
            </nav>

            <span class="flex-1"></span>

            @include('partials.theme-switch')
            @include('partials.locale-switch')

            <div class="hidden items-center gap-1 lg:flex">
                @auth
                    <a href="{{ route('account.show') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">
                        {{ __('Mon compte') }}
                    </a>
                    @if (auth()->user()?->canAccessAdmin())
                        <a href="{{ route('admin.dashboard') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">
                            {{ __('Administration') }}
                        </a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline">{{ __('Déconnexion') }}</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">
                        {{ __('Connexion') }}
                    </a>
                    <a href="{{ route('register') }}" class="btn btn-sm btn-primary">{{ __('Inscription') }}</a>
                @endauth
            </div>

            {{-- Disclosure menu below lg, and a details element rather than a
                 button: the public pages carry no JavaScript, so a button
                 would toggle nothing. --}}
            <details class="relative lg:hidden">
                <summary class="btn btn-sm btn-outline list-none">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                    <span class="sr-only">{{ __('Menu') }}</span>
                </summary>

                <div class="absolute right-0 z-50 mt-2 w-64 space-y-1 rounded-xl border border-slate-200 bg-white p-2 shadow-lg dark:border-slate-700 dark:bg-slate-900">
                    <div class="md:hidden">
                        @include('partials.public-nav', ['mode' => 'stack'])
                    </div>

                    <div class="mt-1 border-t border-slate-200 pt-1 dark:border-slate-700">
                        @auth
                            <a href="{{ route('account.show') }}" class="block rounded-lg px-3 py-2 text-base font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Mon compte') }}</a>
                            @if (auth()->user()?->canAccessAdmin())
                                <a href="{{ route('admin.dashboard') }}" class="block rounded-lg px-3 py-2 text-base font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Administration') }}</a>
                            @endif
                            <form method="POST" action="{{ route('logout') }}" class="px-1 pt-1">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline w-full">{{ __('Déconnexion') }}</button>
                            </form>
                        @else
                            <a href="{{ route('login') }}" class="block rounded-lg px-3 py-2 text-base font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Connexion') }}</a>
                            <a href="{{ route('register') }}" class="block rounded-lg px-3 py-2 text-base font-medium text-accent-700 hover:bg-slate-100 dark:text-accent-300 dark:hover:bg-slate-800">{{ __('Inscription') }}</a>
                        @endauth
                    </div>
                </div>
            </details>
        </div>
    </header>

    <main id="content" class="mx-auto w-full max-w-6xl flex-1 px-4 py-8">
        @if (session('status'))
            <div class="alert alert-success mb-6">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error mb-6">
                <p class="font-medium">{{ __('Des erreurs empêchent la soumission :') }}</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="print-hidden border-t border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="mx-auto max-w-6xl px-4 py-10">
            <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p class="text-sm font-semibold">DoliNews</p>
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        {{-- The service says what was announced, and when; never
                             the current state of a module (SPEC D1). --}}
                        {{ __('Annonces de l\'écosystème Dolibarr : ce qui a été annoncé, et quand.') }}
                    </p>
                </div>

                <div>
                    <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase dark:text-slate-500">{{ __('Suivre') }}</p>
                    <ul class="mt-3 space-y-2 text-sm">
                        <li><a class="link" href="{{ route('feeds.rss') }}">{{ __('Flux RSS') }}</a></li>
                        <li><a class="link" href="{{ route('feeds.json') }}">{{ __('Flux JSON') }}</a></li>
                        <li><a class="link" href="{{ route('pages.api') }}">{{ __('API') }}</a></li>
                    </ul>
                </div>

                <div>
                    <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase dark:text-slate-500">{{ __('Publier') }}</p>
                    <ul class="mt-3 space-y-2 text-sm">
                        <li><a class="link" href="{{ route('pages.editor-guide') }}">{{ __('Guide de l\'éditeur') }}</a></li>
                        <li><a class="link" href="{{ route('review.info') }}">{{ __('La revue') }}</a></li>
                        <li><a class="link" href="{{ route('pages.rules') }}">{{ __('Règles d\'utilisation') }}</a></li>
                    </ul>
                </div>

                <div>
                    <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase dark:text-slate-500">{{ __('Le service') }}</p>
                    <ul class="mt-3 space-y-2 text-sm">
                        <li><a class="link" href="{{ route('pages.commitments') }}">{{ __('Engagements publics') }}</a></li>
                        <li><a class="link" href="{{ route('pages.data') }}">{{ __('Données personnelles') }}</a></li>
                        <li><a class="link" href="{{ route('pages.legal') }}">{{ __('Mentions légales') }}</a></li>
                    </ul>
                </div>
            </div>

            <p class="mt-8 border-t border-slate-200 pt-6 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                {{-- The licence covers the code alone: published contents stay
                     the property of their authors, under CC BY-SA 4.0. --}}
                {{ __('Contenus publiés sous licence CC BY-SA 4.0, propriété de leurs auteurs. Code du service sous GNU AGPL v3.') }}
            </p>
        </div>
    </footer>
</body>
</html>

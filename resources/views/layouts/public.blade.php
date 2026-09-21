<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'DoliNews') - DoliNews</title>
    {{-- Public pages load exactly one stylesheet and no JavaScript:
         ~/docs/laravel/LARAVEL_PAGES_PUBLIQUES.md, a test locks it in. --}}
    <link rel="stylesheet" href="{{ asset('css/dolinews.css') }}">
</head>
<body>
    <header class="site">
        <div class="wrap">
            <a class="brand" href="{{ route('home') }}">DoliNews</a>
            <nav>
                <a href="{{ route('home') }}">{{ __('Le fil') }}</a>
                <a href="{{ route('pages.editor-guide') }}">{{ __('Publier') }}</a>
                <a href="{{ route('review.info') }}">{{ __('La revue') }}</a>
                <a href="{{ route('pages.commitments') }}">{{ __('Engagements') }}</a>
                <a href="{{ route('pages.rules') }}">{{ __('Règles') }}</a>
            </nav>
            <span class="spacer"></span>
            <nav>
                @auth
                    <a href="{{ route('account.show') }}">{{ __('Mon compte') }}</a>
                    @if(auth()->user()?->canAccessAdmin())
                        <a href="{{ route('admin.dashboard') }}">{{ __('Administration') }}</a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}" style="display:inline">
                        @csrf
                        <button type="submit" class="btn-secondary" style="padding:0.25rem 0.6rem">{{ __('Déconnexion') }}</button>
                    </form>
                @else
                    <a href="{{ route('login') }}">{{ __('Connexion') }}</a>
                    <a href="{{ route('register') }}">{{ __('Inscription') }}</a>
                @endauth
            </nav>
            @include('partials.locale-switch')
        </div>
    </header>

    <main class="wrap">
        @if (session('status'))
            <div class="flash">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="errors">
                {{ __('Des erreurs empêchent la soumission :') }}
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="site">
        <div class="wrap">
            <ul>
                <li><a href="{{ route('feeds.rss') }}">{{ __('Flux RSS') }}</a></li>
                <li><a href="{{ route('feeds.json') }}">{{ __('Flux JSON') }}</a></li>
                <li><a href="{{ route('pages.editor-guide') }}">{{ __('Guide de l\'éditeur') }}</a></li>
                <li><a href="{{ route('pages.api') }}">{{ __('API') }}</a></li>
                <li><a href="{{ route('pages.commitments') }}">{{ __('Engagements publics') }}</a></li>
                <li><a href="{{ route('pages.rules') }}">{{ __('Règles d\'utilisation') }}</a></li>
                <li><a href="{{ route('pages.data') }}">{{ __('Données personnelles') }}</a></li>
                <li><a href="{{ route('pages.legal') }}">{{ __('Mentions légales') }}</a></li>
            </ul>
            <p>{{ __('Annonces de l\'écosystème Dolibarr : ce qui a été annoncé, et quand.') }}</p>
        </div>
    </footer>
</body>
</html>

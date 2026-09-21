<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Connexion') - DoliNews</title>
    <link rel="stylesheet" href="{{ asset('css/dolinews.css') }}">
</head>
<body>
    <main>
        <div class="guest-card">
            @if (session('status'))
                <div class="flash">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="errors">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </div>
        {{-- The way back to the public pages: without it, an account that
             cannot log in has no way out but the browser's back button. --}}
        <p style="text-align:center">
            <a class="back-home" href="{{ route('home') }}">{{ __('Retour à l\'accueil') }}</a>
        </p>
    </main>
</body>
</html>

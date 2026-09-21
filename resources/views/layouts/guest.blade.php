<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('Connexion')) - DoliNews</title>
    {{-- Same single stylesheet as the public pages, and no JavaScript entry. --}}
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen flex-col bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <main class="flex flex-1 items-center justify-center px-4 py-12">
        <div class="w-full max-w-md">
            <p class="text-center text-lg font-semibold tracking-tight">
                <a href="{{ route('home') }}">DoliNews</a>
            </p>
            <p class="mt-1 text-center text-sm text-slate-500 dark:text-slate-400">
                {{ __('Annonces de l\'écosystème Dolibarr.') }}
            </p>

            <div class="card mt-6">
                <div class="card-body">
                    @if (session('status'))
                        <div class="alert alert-success mb-4">{{ session('status') }}</div>
                    @endif

                    @if ($errors->any())
                        <div class="alert alert-error mb-4">
                            <ul class="list-disc pl-5">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @yield('content')
                </div>
            </div>

            {{-- The way back to the public pages: without it, an account that
                 cannot log in has no way out but the browser's back button. --}}
            <div class="mt-6 flex flex-col items-center gap-3">
                <a class="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white" href="{{ route('home') }}">
                    {{ __('Retour à l\'accueil') }}
                </a>
                @include('partials.locale-switch')
            </div>
        </div>
    </main>
</body>
</html>

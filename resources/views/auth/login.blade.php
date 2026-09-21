@extends('layouts.guest')

@section('title', __('Connexion'))

@section('content')
    <h1 class="text-xl font-semibold tracking-tight">{{ __('Connexion') }}</h1>

    <form method="POST" action="{{ route('login.store') }}" class="mt-5 space-y-4">
        @csrf

        <div class="form-control">
            <label class="label" for="email">{{ __('Adresse électronique') }}</label>
            <input class="input" id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
        </div>

        <div class="form-control">
            <label class="label" for="password">{{ __('Mot de passe') }}</label>
            <input class="input" id="password" type="password" name="password" required autocomplete="current-password">
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input id="remember" type="checkbox" name="remember">
            <span>{{ __('Se souvenir de moi') }}</span>
        </label>

        <button type="submit" class="btn btn-primary w-full">{{ __('Se connecter') }}</button>
    </form>

    <p class="mt-5 flex flex-wrap items-center gap-x-2 text-sm">
        <a class="link" href="{{ route('password.request') }}">{{ __('Mot de passe oublié') }}</a>
        <span aria-hidden="true">-</span>
        <a class="link" href="{{ route('register') }}">{{ __('Créer un compte') }}</a>
    </p>
@endsection

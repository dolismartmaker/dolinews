@extends('layouts.guest')

@section('content')
    <h1>{{ __('Connexion') }}</h1>

    <form method="POST" action="{{ route('login.store') }}" class="stack">
        @csrf

        <div class="field">
            <label for="email">{{ __('Adresse électronique') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
        </div>

        <div class="field">
            <label for="password">{{ __('Mot de passe') }}</label>
            <input id="password" type="password" name="password" required>
        </div>

        <div class="field checkbox-field">
            <input id="remember" type="checkbox" name="remember">
            <label for="remember">{{ __('Se souvenir de moi') }}</label>
        </div>

        <button type="submit">{{ __('Se connecter') }}</button>
    </form>

    <p style="margin-top:1rem; font-size:0.9rem">
        <a href="{{ route('password.request') }}">{{ __('Mot de passe oublié') }}</a>
        -
        <a href="{{ route('register') }}">{{ __('Créer un compte') }}</a>
    </p>
@endsection

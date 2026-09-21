@extends('layouts.guest')

@section('content')
    <h1>{{ __('Nouveau mot de passe') }}</h1>

    <form method="POST" action="{{ route('password.update') }}" class="stack">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        <div class="field">
            <label for="email">{{ __('Adresse électronique') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required>
        </div>

        <div class="field">
            <label for="password">{{ __('Mot de passe') }}</label>
            <input id="password" type="password" name="password" required minlength="12">
        </div>

        <div class="field">
            <label for="password_confirmation">{{ __('Confirmer le mot de passe') }}</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required minlength="12">
        </div>

        <button type="submit">{{ __('Réinitialiser le mot de passe') }}</button>
    </form>
@endsection

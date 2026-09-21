@extends('layouts.guest')

@section('content')
    <h1>{{ __('Créer un compte lecteur') }}</h1>

    <p class="hint" style="margin-bottom:1rem">
        {{ __('Un compte lecteur sert aux abonnements : suivre des projets et des éditeurs, avec un flux personnel. La publication exige une vérification de contribution, distincte et ultérieure.') }}
    </p>

    <form method="POST" action="{{ route('register.store') }}" class="stack">
        @csrf

        <div class="field">
            <label for="name">{{ __('Nom') }}</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="100" autofocus>
        </div>

        <div class="field">
            <label for="email">{{ __('Adresse électronique') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required>
        </div>

        <div class="field">
            <label for="password">{{ __('Mot de passe') }}</label>
            <input id="password" type="password" name="password" required minlength="12">
            <p class="hint">{{ __('Au moins 12 caractères.') }}</p>
        </div>

        <div class="field">
            <label for="password_confirmation">{{ __('Confirmer le mot de passe') }}</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required minlength="12">
        </div>

        <button type="submit">{{ __('Créer le compte') }}</button>
    </form>
@endsection

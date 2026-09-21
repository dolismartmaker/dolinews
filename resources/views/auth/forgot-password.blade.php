@extends('layouts.guest')

@section('content')
    <h1>{{ __('Mot de passe oublié') }}</h1>

    <form method="POST" action="{{ route('password.email') }}" class="stack">
        @csrf

        <div class="field">
            <label for="email">{{ __('Adresse électronique') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
        </div>

        <button type="submit">{{ __('Envoyer le lien de réinitialisation') }}</button>
    </form>
@endsection

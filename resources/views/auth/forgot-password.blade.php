@extends('layouts.guest')

@section('title', __('Mot de passe oublié'))

@section('content')
    <h1 class="text-xl font-semibold tracking-tight">{{ __('Mot de passe oublié') }}</h1>

    <form method="POST" action="{{ route('password.email') }}" class="mt-5 space-y-4">
        @csrf

        <div class="form-control">
            <label class="label" for="email">{{ __('Adresse électronique') }}</label>
            <input class="input" id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
        </div>

        <button type="submit" class="btn btn-primary w-full">{{ __('Envoyer le lien de réinitialisation') }}</button>
    </form>

    <p class="mt-5 text-sm">
        <a class="link" href="{{ route('login') }}">{{ __('Retour à la connexion') }}</a>
    </p>
@endsection

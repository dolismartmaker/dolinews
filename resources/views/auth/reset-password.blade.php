@extends('layouts.guest')

@section('title', __('Nouveau mot de passe'))

@section('content')
    <h1 class="text-xl font-semibold tracking-tight">{{ __('Nouveau mot de passe') }}</h1>

    <form method="POST" action="{{ route('password.update') }}" class="mt-5 space-y-4">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        <div class="form-control">
            <label class="label" for="email">{{ __('Adresse électronique') }}</label>
            <input class="input" id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
        </div>

        <div class="form-control">
            <label class="label" for="password">{{ __('Mot de passe') }}</label>
            <input class="input" id="password" type="password" name="password" required minlength="12" autocomplete="new-password">
            <p class="field-hint">{{ __('Au moins 12 caractères.') }}</p>
        </div>

        <div class="form-control">
            <label class="label" for="password_confirmation">{{ __('Confirmer le mot de passe') }}</label>
            <input class="input" id="password_confirmation" type="password" name="password_confirmation" required minlength="12" autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary w-full">{{ __('Réinitialiser le mot de passe') }}</button>
    </form>
@endsection

@extends('layouts.guest')

@section('title', __('Créer un compte lecteur'))

@section('content')
    <h1 class="text-xl font-semibold tracking-tight">{{ __('Créer un compte lecteur') }}</h1>

    {{-- Two classes of accounts (SPEC 3): a reader account is free to open and
         carries no write right; writing is the contributor account, admitted
         after proof of contribution. --}}
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
        {{ __('Un compte lecteur sert aux abonnements : suivre des projets et des éditeurs, avec un flux personnel. La publication exige une vérification de contribution, distincte et ultérieure.') }}
    </p>

    <form method="POST" action="{{ route('register.store') }}" class="mt-5 space-y-4">
        @csrf

        <div class="form-control">
            <label class="label" for="name">{{ __('Nom') }}</label>
            <input class="input" id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="100" autofocus autocomplete="name">
        </div>

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

        <button type="submit" class="btn btn-primary w-full">{{ __('Créer le compte') }}</button>
    </form>

    <p class="mt-5 text-sm">
        <a class="link" href="{{ route('login') }}">{{ __('J\'ai déjà un compte') }}</a>
    </p>
@endsection

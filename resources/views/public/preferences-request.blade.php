@extends('layouts.public')

@section('title', __('Mes préférences d\'abonnement'))

@section('content')
    <div class="mx-auto max-w-xl">
        <div class="card">
            <div class="card-body sm:p-8">
                @if (session('sent'))
                    {{-- The same answer whether the address is known here
                         or not: this form is public, and a different one
                         would tell a stranger who subscribed. --}}
                    <h1 class="text-2xl font-semibold tracking-tight">{{ __('Courriel envoyé') }}</h1>
                    <p class="mt-3 text-slate-700 dark:text-slate-200">
                        {{ __('Si cette adresse est abonnée, elle vient de recevoir un lien vers ses préférences.') }}
                    </p>
                    <div class="mt-5">
                        <a class="btn btn-outline" href="{{ route('home') }}">{{ __('Le fil') }}</a>
                    </div>
                @else
                    <h1 class="text-2xl font-semibold tracking-tight">{{ __('Mes préférences d\'abonnement') }}</h1>
                    <p class="mt-3 text-slate-700 dark:text-slate-200">
                        {{ __('Indiquez votre adresse : vous recevrez un lien pour choisir ce que vous recevez et à quelle fréquence. Aucun mot de passe n\'est nécessaire.') }}
                    </p>

                    <form method="POST" action="{{ route('subscriptions.preferences.send') }}" class="mt-5 space-y-4">
                        @csrf

                        <div class="form-control">
                            <label class="label" for="pref-email">{{ __('Votre adresse de courriel') }}</label>
                            <input class="input" id="pref-email" type="email" name="email"
                                value="{{ old('email') }}" required maxlength="255" autocomplete="email">
                        </div>

                        <div class="hidden" aria-hidden="true">
                            <label for="pref-website">{{ __('Laissez ce champ vide') }}</label>
                            <input id="pref-website" type="text" name="website" tabindex="-1" autocomplete="off">
                        </div>

                        <button type="submit" class="btn btn-primary">{{ __('Recevoir le lien') }}</button>
                    </form>

                    {{-- Whoever writes here keeps a password, and that is
                         the door they use: a mail link must not open an
                         account that can publish or moderate. --}}
                    <p class="mt-4 border-t border-slate-100 pt-4 text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
                        {{ __('Vous publiez des annonces sur DoliNews ?') }}
                        <a class="link" href="{{ route('login') }}">{{ __('Connexion') }}</a>
                    </p>
                @endif
            </div>
        </div>
    </div>
@endsection

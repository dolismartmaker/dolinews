@extends('layouts.account')

@section('title', __('Mot de passe'))

@section('account')
    <h1 class="mb-5 text-2xl font-semibold tracking-tight">{{ __('Mot de passe') }}</h1>

    <div class="space-y-6">
        @if ($forced)
            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">{{ __('Changement obligatoire') }}</h2>
                    <p class="mt-2 text-slate-700 dark:text-slate-200">
                        {{ __('Ce compte utilise encore le mot de passe posé à l\'installation, lu dans le fichier de configuration du serveur. Choisissez-en un autre pour accéder au service.') }}
                    </p>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-body">
                <h2 class="card-title">{{ __('Nouveau mot de passe') }}</h2>

                <form method="POST" action="{{ route('account.password.update') }}" class="mt-4 space-y-4">
                    @csrf

                    <div class="form-control">
                        <label class="label" for="current_password">{{ __('Mot de passe actuel') }}</label>
                        <input class="input" id="current_password" type="password" name="current_password" required autocomplete="current-password">
                    </div>

                    <div class="form-control">
                        <label class="label" for="password">{{ __('Nouveau mot de passe') }}</label>
                        <input class="input" id="password" type="password" name="password" required autocomplete="new-password" minlength="12">
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Douze caractères au minimum.') }}</p>
                    </div>

                    <div class="form-control">
                        <label class="label" for="password_confirmation">{{ __('Confirmation') }}</label>
                        <input class="input" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                    </div>

                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Le changement révoque les jetons d\'API et les autres sessions de ce compte.') }}
                    </p>

                    <button type="submit" class="btn btn-primary">{{ __('Changer le mot de passe') }}</button>
                </form>
            </div>
        </div>
    </div>
@endsection

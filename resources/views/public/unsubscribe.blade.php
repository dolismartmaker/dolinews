@extends('layouts.public')

@section('title', __('Ne plus recevoir les courriels'))

{{-- The address carries a token, which is the whole authorisation
     (SPEC 6.4): it belongs in no index. --}}
@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
    <div class="mx-auto max-w-xl">
        <div class="card">
            <div class="card-body sm:p-8">
                @if ($done)
                    <h1 class="text-2xl font-semibold tracking-tight">{{ __('Courriels arrêtés') }}</h1>
                    <p class="mt-3 text-slate-700 dark:text-slate-200">
                        {{ __('Plus aucun courriel d\'abonnement ne part vers cette adresse :') }} {{ $email }}
                    </p>
                    {{-- Nothing else is revoked: the feed and the watches
                         outlive the mails, and a reader who stops the
                         mails has not asked to lose their account. --}}
                    <p class="mt-3 text-slate-600 dark:text-slate-300">
                        {{ __('Vos abonnements et votre flux personnel restent en place. Vous pouvez reprendre les courriels depuis votre compte.') }}
                    </p>
                    <div class="mt-5 flex flex-wrap gap-2">
                        <a class="btn btn-outline" href="{{ route('home') }}">{{ __('Le fil') }}</a>
                        <a class="btn btn-outline" href="{{ route('account.show') }}">{{ __('Mon compte') }}</a>
                    </div>
                @else
                    <h1 class="text-2xl font-semibold tracking-tight">{{ __('Ne plus recevoir les courriels') }}</h1>
                    <p class="mt-3 text-slate-700 dark:text-slate-200">
                        {{ __('Confirmez l\'arrêt des courriels d\'abonnement vers cette adresse :') }} {{ $email }}
                    </p>
                    <form method="POST" action="{{ route('unsubscribe.store', ['token' => $token]) }}" class="mt-5">
                        @csrf
                        <button type="submit" class="btn btn-primary">{{ __('Arrêter les courriels') }}</button>
                    </form>
                    <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Pour ne recevoir que certaines annonces plutôt que plus aucune, réglez la fréquence et les filtres depuis votre compte.') }}
                    </p>
                @endif
            </div>
        </div>
    </div>
@endsection

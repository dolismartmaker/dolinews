@extends('layouts.public')

@section('title', __('Confirmez votre abonnement'))

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
                    <h1 class="text-2xl font-semibold tracking-tight">{{ __('Abonnement confirmé') }}</h1>
                    <p class="mt-3 text-slate-700 dark:text-slate-200">
                        {{ __('Vous recevrez un courriel dès qu\'une annonce paraît. Chacun porte un lien pour tout arrêter.') }}
                    </p>
                    <p class="mt-3 text-slate-600 dark:text-slate-300">
                        {{ __('Pour changer la fréquence ou ce que vous suivez, demandez un lien depuis la page des préférences.') }}
                    </p>
                    <div class="mt-5 flex flex-wrap gap-2">
                        <a class="btn btn-primary" href="{{ route('home') }}">{{ __('Le fil') }}</a>
                        <a class="btn btn-outline" href="{{ route('subscriptions.preferences.request') }}">{{ __('Mes préférences') }}</a>
                    </div>
                @elseif ($failed)
                    {{-- Says which, rather than a bare 404: an expired
                         link is the common case, and a reader who reads
                         "page introuvable" concludes the service is
                         broken instead of asking for a fresh one. --}}
                    <h1 class="text-2xl font-semibold tracking-tight">{{ __('Lien expiré ou déjà utilisé') }}</h1>
                    <p class="mt-3 text-slate-700 dark:text-slate-200">
                        {{ __('Ce lien de confirmation n\'est plus valable. Reprenez depuis la fiche du projet ou de l\'éditeur que vous vouliez suivre.') }}
                    </p>
                    <div class="mt-5">
                        <a class="btn btn-primary" href="{{ route('home') }}">{{ __('Le fil') }}</a>
                    </div>
                @else
                    <h1 class="text-2xl font-semibold tracking-tight">{{ __('Confirmez votre abonnement') }}</h1>
                    <p class="mt-3 text-slate-700 dark:text-slate-200">
                        {{ __('Un dernier geste et c\'est fait : confirmez que cette adresse est bien la vôtre.') }}
                    </p>
                    <form method="POST" action="{{ route('subscribe.confirm.store', ['token' => $token]) }}" class="mt-5">
                        @csrf
                        <button type="submit" class="btn btn-primary">{{ __('Confirmer mon abonnement') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endsection

@extends('layouts.public')

@section('title', __('Engagements publics'))

@section('content')
    <div class="card mx-auto max-w-3xl">
        <div class="card-body sm:p-8">
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Engagements publics') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Version') }} {{ $version }} - {{ __('ces engagements sont publiés et versionnés dès la mise en ligne') }}</p>

            {{-- The core is free and stays free (SPEC 12): publication,
                 consultation, feeds, subscriptions and API are never charged
                 for. Published here, they are opposable. --}}
            <ul class="prose-dolinews mt-6 list-disc space-y-2 pl-6">
                <li>{{ __('la publication d\'annonces reste gratuite, sans limite de durée ;') }}</li>
                <li>{{ __('la consultation et les flux génériques restent gratuits et sans compte ; l\'abonnement personnalisé est gratuit, avec un compte lecteur ;') }}</li>
                <li>{{ __('l\'API reste publique, documentée et versionnée, en lecture comme en écriture ;') }}</li>
                <li>{{ __('les données publiées restent exportables par leurs auteurs ;') }}</li>
            </ul>
        </div>
    </div>
@endsection

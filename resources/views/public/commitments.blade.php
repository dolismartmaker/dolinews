@extends('layouts.public')

@section('title', __('Engagements publics'))

@section('content')
    <div class="card">
        <h1 style="font-size:1.3rem; margin:0 0 0.5rem">{{ __('Engagements publics') }}</h1>
        <p class="hint">{{ __('Version') }} {{ $version }} - {{ __('ces engagements sont publiés et versionnés dès la mise en ligne (SPEC 12)') }}</p>

        <ul style="padding-left:1.1rem; line-height:1.8">
            <li>{{ __('la publication d\'annonces reste gratuite, sans limite de durée ;') }}</li>
            <li>{{ __('la consultation et les flux génériques restent gratuits et sans compte ; l\'abonnement personnalisé est gratuit, avec un compte lecteur ;') }}</li>
            <li>{{ __('l\'API reste publique, documentée et versionnée, en lecture comme en écriture ;') }}</li>
            <li>{{ __('les données publiées restent exportables par leurs auteurs ;') }}</li>
            <li>{{ __('une prestation payante de traduction peut être proposée : elle porte uniquement sur la prestation de travail. Une annonce non traduite est publiée, diffusée et filtrée exactement comme une annonce traduite, et aucune fonctionnalité de diffusion, de visibilité ou de tri ne sera monnayée ;') }}</li>
            <li>{{ __('les règles d\'utilisation sont publiées et versionnées au même titre que ces engagements : une sanction ne peut s\'appuyer que sur une règle numérotée existante au moment des faits.') }}</li>
        </ul>
    </div>
@endsection

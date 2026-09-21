@extends('layouts.public')

@section('title', __('Données personnelles'))

@section('content')
    <div class="card">
        <h1 style="font-size:1.3rem; margin:0 0 0.75rem">{{ __('Données personnelles') }}</h1>

        <p>{{ __('Inventaire des traitements (SPEC 9.8) : empreintes des auteurs de commits moissonnées dans les dépôts de référence, empreintes des preuves de contribution, courriels de contact des éditeurs, comptes, médias, fils de revue, journal de modération. Les adresses de commit ne sont jamais stockées en clair : uniquement l\'empreinte salée dite poivrée sha256(poivre || adresse).') }}</p>

        <p>{{ __('Le canal interne des fils de revue porte souvent une appréciation sur une personne : il entre dans le périmètre d\'une demande d\'accès, sous réserve des données concernant des tiers.') }}</p>

        <p>{{ __('À la suppression d\'un compte : les données de publication sont conservées sous forme minimisée si un contenu reste en ligne ; les empreintes de preuves sont conservées pour empêcher une réinscription en contournement, ce qui relève de l\'intérêt légitime.') }}</p>

        <p>{{ __('Les demandes d\'accès, de rectification et d\'effacement sont traitées par l\'équipe de modération selon une procédure écrite. Les durées de conservation exactes sont fixées avant la mise en ligne.') }}</p>
    </div>
@endsection

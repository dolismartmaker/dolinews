@extends('layouts.public')

@section('title', __('Données personnelles'))

@section('content')
    <div class="card mx-auto max-w-3xl">
        <div class="card-body sm:p-8">
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Données personnelles') }}</h1>

            <div class="prose-dolinews mt-4">
                <p>{{ __('Inventaire des traitements : empreintes des auteurs de commits moissonnées dans les dépôts de référence, empreintes des preuves de contribution, courriels de contact des éditeurs, comptes, médias, fils de revue, journal de modération. Les adresses de commit ne sont jamais stockées en clair : uniquement l\'empreinte salée dite poivrée sha256(poivre || adresse).') }}</p>

                <p>{{ __('Le canal interne des fils de revue porte souvent une appréciation sur une personne : il entre dans le périmètre d\'une demande d\'accès, sous réserve des données concernant des tiers.') }}</p>

                <p>{{ __('Signaler un contenu demande une adresse de courriel : elle sert à l\'équipe de modération pour vous demander une précision, et à rien d\'autre. Elle n\'est jamais communiquée à l\'auteur du contenu signalé.') }}</p>

                <p>{{ __('À la suppression d\'un compte : les données de publication sont conservées sous forme minimisée si un contenu reste en ligne ; les empreintes de preuves sont conservées pour empêcher une réinscription en contournement, ce qui relève de l\'intérêt légitime.') }}</p>

                <p>{{ __('Les demandes d\'accès, de rectification et d\'effacement sont traitées par l\'équipe de modération selon une procédure écrite. Les durées de conservation exactes sont fixées avant la mise en ligne.') }}</p>
            </div>
        </div>
    </div>
@endsection

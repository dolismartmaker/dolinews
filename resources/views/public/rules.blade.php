@extends('layouts.public')

@section('title', __('Règles d\'utilisation'))

@section('content')
    <div class="card">
        <h1 style="font-size:1.3rem; margin:0 0 0.5rem">{{ __('Règles d\'utilisation') }}</h1>
        <p class="hint">{{ __('Version') }} {{ $version }} - {{ __('écrites, publiées et versionnées avant la mise en ligne (SPEC 9.2)') }}</p>

        <p>{{ __('Une sanction ne peut s\'appuyer que sur une règle numérotée existante au moment des faits : pas d\'application rétroactive, pas de règle invoquée oralement.') }}</p>

        <h2 style="font-size:1.05rem; margin:1.25rem 0 0.5rem">{{ __('Manquements et sanctions') }}</h2>
        <table class="plain">
            <thead>
                <tr>
                    <th>{{ __('Règle') }}</th>
                    <th>{{ __('Manquement') }}</th>
                    <th>{{ __('Sanctions encourues') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>R1</td>
                    <td>{{ __('Spam : soumission répétée de contenu sans rapport avec l\'écosystème Dolibarr.') }}</td>
                    <td>{{ __('avertissement, masquage, suspension temporaire, suspension définitive') }}</td>
                </tr>
                <tr>
                    <td>R2</td>
                    <td>{{ __('Usurpation d\'identité ou de fiche : revendiquer le projet ou l\'identité d\'autrui.') }}</td>
                    <td>{{ __('masquage, suspension temporaire ou définitive, transfert de la fiche') }}</td>
                </tr>
                <tr>
                    <td>R3</td>
                    <td>{{ __('Contenu illicite.') }}</td>
                    <td>{{ __('retrait immédiat, suspension définitive, signalement aux autorités compétentes') }}</td>
                </tr>
                <tr>
                    <td>R4</td>
                    <td>{{ __('Lien trompeur : destination contraire à l\'intitulé, raccourcisseur masquant la destination.') }}</td>
                    <td>{{ __('refus de l\'annonce, avertissement, masquage') }}</td>
                </tr>
                <tr>
                    <td>R5</td>
                    <td>{{ __('Détournement de la file prioritaire : article marqué sécurité sans objet de sécurité.') }}</td>
                    <td>{{ __('avertissement, perte de priorité, suspension temporaire') }}</td>
                </tr>
            </tbody>
        </table>

        <h2 style="font-size:1.05rem; margin:1.25rem 0 0.5rem">{{ __('Graduation') }}</h2>
        <p>{{ __('Du plus léger au plus lourd : avertissement, masquage d\'article, suspension temporaire, suspension définitive. Chaque acte de modération est consigné avec la règle invoquée et son motif ; l\'auteur concerné dispose d\'une voie de contestation écrite.') }}</p>
    </div>
@endsection

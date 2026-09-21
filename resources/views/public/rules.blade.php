@extends('layouts.public')

@section('title', __('Règles d\'utilisation'))

@section('content')
    <div class="card mx-auto max-w-3xl">
        <div class="card-body sm:p-8">
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Règles d\'utilisation') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Version') }} {{ $version }} - {{ __('écrites, publiées et versionnées avant la mise en ligne') }}</p>

            <p class="mt-4 text-slate-700 dark:text-slate-200">{{ __('Une sanction ne peut s\'appuyer que sur une règle numérotée existante au moment des faits : pas d\'application rétroactive, pas de règle invoquée oralement.') }}</p>

            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ __('En soumettant, l\'auteur déclare détenir les droits sur son contenu et le place sous licence CC BY-SA 4.0 ; il s\'engage à n\'enfreindre aucune loi ni aucun droit de tiers, notamment le droit des marques.') }}</p>

            <h2 class="mt-8 text-lg font-semibold">{{ __('Manquements et sanctions') }}</h2>
            <div class="mt-3 overflow-x-auto">
            <table class="table-plain">
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
                    <tr>
                        <td>R6</td>
                        <td>{{ __('Atteinte aux droits de tiers : contenu dont l\'auteur ne détient pas les droits, ou portant atteinte à une marque, un nom commercial ou un logo.') }}</td>
                        <td>{{ __('retrait immédiat, avertissement, suspension temporaire ou définitive') }}</td>
                    </tr>
                </tbody>
            </table>
            </div>

            <h2 class="mt-8 text-lg font-semibold">{{ __('Graduation') }}</h2>
            <p class="mt-2 text-slate-700 dark:text-slate-200">{{ __('Du plus léger au plus lourd : avertissement, masquage d\'article, suspension temporaire, suspension définitive. Chaque acte de modération est consigné avec la règle invoquée et son motif ; l\'auteur concerné dispose d\'une voie de contestation écrite.') }}</p>
        </div>
    </div>
@endsection

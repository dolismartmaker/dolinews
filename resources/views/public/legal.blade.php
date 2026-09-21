@extends('layouts.public')

@section('title', __('Mentions légales'))

@section('content')
    <div class="card">
        <h1 style="font-size:1.3rem; margin:0 0 0.75rem">{{ __('Mentions légales') }}</h1>

        <p>{{ __('DoliNews est un fil d\'annonces pour l\'écosystème Dolibarr, alimenté par les éditeurs de modules et de services.') }}</p>

        <p>{{ __('La revue a priori fait de l\'exploitant l\'éditeur des contenus validés, responsable de ceux-ci au sens de la LCEN (SPEC 9.7). C\'est un choix délibéré, prix du contrôle éditorial.') }}</p>

        <p>{{ __('Le code du service est publié sous licence GNU AGPL v3. La licence porte sur le code uniquement : les contenus publiés restent la propriété de leurs auteurs.') }}</p>

        <p>{{ __('Le nom du service et l\'usage du préfixe "Doli" restent à vérifier au regard de la politique de la Fondation Dolibarr avant tout dépôt et toute communication (SPEC 15).') }}</p>
    </div>
@endsection

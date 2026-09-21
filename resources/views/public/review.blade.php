@extends('layouts.public')

@section('title', __('La revue'))

@section('content')
    <div class="card">
        <h1 style="font-size:1.3rem; margin:0 0 0.75rem">{{ __('Comment fonctionne la revue') }}</h1>

        <p>{{ __('Toute publication passe par une revue a priori, sur le modèle d\'une pull request : soumission, revue par l\'équipe, acceptation par trois modérateurs, publication automatique.') }}</p>

        <p>{{ __('Une annonce de sécurité prend la file en priorité : elle ne peut pas attendre une semaine qu\'un bénévole passe.') }}</p>

        <div class="stats">
            <div class="stat">
                <div class="value">{{ $medianSeconds !== null ? round($medianSeconds / 3600).' h' : '-' }}</div>
                <div class="label">{{ __('Délai observé (médiane des dernières décisions)') }}</div>
            </div>
            <div class="stat">
                <div class="value">{{ $oldestPendingDays !== null ? $oldestPendingDays.' '.__('jours') : '-' }}</div>
                <div class="label">{{ __('Attente la plus ancienne') }}</div>
            </div>
            <div class="stat">
                <div class="value">{{ $pendingCount }}</div>
                <div class="label">{{ __('Soumissions en attente') }}</div>
            </div>
        </div>

        <p class="hint">
            {{ __('Ces chiffres sont des mesures, jamais des engagements : un article est publié quand la revue l\'a validé, pas avant. La médiane ne couvre que les articles publiés : sans l\'âge du plus ancien en attente, une file encombrée paraîtrait meilleure.') }}
        </p>
    </div>
@endsection

@extends('layouts.public')

@section('title', __('La revue'))

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="card">
            <div class="card-body sm:p-8">
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Comment fonctionne la revue') }}</h1>

                <div class="prose-dolinews mt-4">
                    <p>{{ __('Toute publication passe par une revue a priori, sur le modèle d\'une pull request : soumission, revue par l\'équipe, acceptation par trois modérateurs, publication automatique.') }}</p>
                    <p>{{ __('Une annonce de sécurité prend la file en priorité : elle ne peut pas attendre une semaine qu\'un bénévole passe.') }}</p>
                </div>
            </div>
        </div>

        {{-- No target delay is ever announced (SPEC 9.5): what is published is
             the observed delay, which measures without promising. --}}
        <dl class="grid gap-4 sm:grid-cols-3">
            <div class="stat">
                <dd class="stat-value">{{ $medianSeconds !== null ? round($medianSeconds / 3600).' h' : '-' }}</dd>
                <dt class="stat-label">{{ __('Délai observé (médiane des dernières décisions)') }}</dt>
            </div>
            <div class="stat">
                <dd class="stat-value">{{ $oldestPendingDays !== null ? $oldestPendingDays.' '.__('jours') : '-' }}</dd>
                <dt class="stat-label">{{ __('Attente la plus ancienne') }}</dt>
            </div>
            <div class="stat">
                <dd class="stat-value">{{ $pendingCount }}</dd>
                <dt class="stat-label">{{ __('Soumissions en attente') }}</dt>
            </div>
        </dl>

        <p class="text-sm text-slate-500 dark:text-slate-400">
            {{ __('Ces chiffres sont des mesures, jamais des engagements : un article est publié quand la revue l\'a validé, pas avant. La médiane ne couvre que les articles publiés : sans l\'âge du plus ancien en attente, une file encombrée paraîtrait meilleure.') }}
        </p>

        <div class="card">
            <div class="card-body">
                <h2 class="card-title">{{ __('Publier une annonce') }}</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    {{ __('Le guide de l\'éditeur décrit le parcours complet, de l\'ouverture du compte à la première soumission.') }}
                </p>
                <a class="btn btn-primary mt-4" href="{{ route('pages.editor-guide') }}">{{ __('Guide de l\'éditeur') }}</a>
            </div>
        </div>
    </div>
@endsection

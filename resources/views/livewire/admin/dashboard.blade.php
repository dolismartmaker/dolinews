<div>
    <div class="mb-5">
        <h1 class="text-2xl font-semibold tracking-tight">{{ __('Tableau de bord') }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ __('L\'état du service, mesuré. Le délai est observé, jamais promis.') }}
        </p>
    </div>

    @if ($bootstrapOpen)
        {{-- The bootstrap phase is bounded: a ceiling set before opening,
             and closure at the constitution of the team or at the first
             third-party submission (SPEC 5.1). Saying so on every visit,
             with the distance left to run, is what keeps it from lasting. --}}
        <div class="alert alert-info mb-5">
            {{ __('Phase d\'amorçage ouverte : le super administrateur peut publier ses propres annonces sans quorum, :used sur un plafond de :ceiling, jusqu\'à la première soumission d\'un tiers ou à la constitution de l\'équipe au plancher de six.', ['used' => $bootstrapUsed, 'ceiling' => $bootstrapCeiling]) }}
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {{-- The queue comes first and is the only tile that leads somewhere:
             it is the one figure that calls for an action. --}}
        <a href="{{ route('admin.review') }}" class="stat transition hover:border-accent-300 dark:hover:border-accent-700">
            <div class="stat-value">{{ $pendingArticles }}</div>
            <div class="stat-label">{{ __('En file de revue') }}</div>
            <span class="mt-2 inline-block text-sm text-accent-700 dark:text-accent-300">{{ __('Ouvrir la file') }}</span>
        </a>

        <div class="stat">
            <div class="stat-value">{{ $medianSeconds !== null ? round($medianSeconds / 3600).' h' : '-' }}</div>
            <div class="stat-label">{{ __('Délai observé (médiane)') }}</div>
        </div>

        <div class="stat">
            <div class="stat-value">
                {{ $activeModerators }}
                @if ($activeModerators < 6)
                    <span class="badge badge-warning align-middle">{{ __('sous le plancher') }}</span>
                @endif
            </div>
            {{-- The floor of six is what conditions the launch (SPEC 9.1), so
                 the figure is never shown without it. --}}
            <div class="stat-label">{{ __('Modérateurs (plancher : six)') }}</div>
        </div>

        <div class="stat">
            <div class="stat-value">{{ $activeContributors }}</div>
            <div class="stat-label">{{ __('Contributeurs vérifiés') }}</div>
        </div>

        <div class="stat">
            <div class="stat-value">{{ $publishedArticles }}</div>
            <div class="stat-label">{{ __('Articles publiés') }}</div>
        </div>

        <div class="stat">
            <div class="stat-value">{{ $projectCount }}</div>
            <div class="stat-label">{{ __('Fiches projet') }}</div>
        </div>

        <div class="stat">
            <div class="stat-value">{{ $apiCallsThisMonth }}</div>
            <div class="stat-label">{{ __('Appels API ce mois') }}</div>
        </div>
    </div>
</div>

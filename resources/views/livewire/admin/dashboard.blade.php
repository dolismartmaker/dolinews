<div>
    <x-mary-header :title="__('Tableau de bord')" :subtitle="__('L\'état du service, mesuré. Le délai est observé, jamais promis.')" separator />

    @if ($bootstrapOpen)
        {{-- The bootstrap phase is bounded: a ceiling set before opening,
             and closure at the constitution of the team or at the first
             third-party submission (SPEC 5.1). Saying so on every visit,
             with the distance left to run, is what keeps it from lasting. --}}
        <x-mary-alert icon="o-information-circle" class="alert-info mb-5"
                      :title="__('Phase d\'amorçage ouverte : le super administrateur peut publier ses propres annonces sans quorum, :used sur un plafond de :ceiling, jusqu\'à la première soumission d\'un tiers ou à la constitution de l\'équipe au plancher de six.', ['used' => $bootstrapUsed, 'ceiling' => $bootstrapCeiling])" />
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {{-- The queue comes first and is one of the two tiles that lead
             somewhere: it is a figure that calls for an action. --}}
        <a href="{{ route('admin.review.index') }}" class="block rounded-box transition hover:bg-base-200">
            <x-mary-stat :title="__('En file de revue')" :value="$pendingArticles" icon="o-inbox-arrow-down" :description="__('Ouvrir la file')" />
        </a>

        {{-- The second tile that leads somewhere: what is published and
             should perhaps not be (SPEC 9.9). Flagged as soon as it is not
             zero, because a report waiting is a content online. --}}
        <a href="{{ route('admin.reports.index') }}" class="block rounded-box transition hover:bg-base-200">
            <x-mary-stat :title="__('Signalements ouverts')" :value="$openReports" icon="o-flag"
                         :description="$openReports > 0 ? __('à traiter') : __('Ouvrir la file')"
                         :color="$openReports > 0 ? 'text-warning' : null" />
        </a>

        <x-mary-stat :title="__('Délai observé (médiane)')" :value="$medianSeconds !== null ? round($medianSeconds / 3600).' h' : '-'" icon="o-clock" />

        {{-- The floor of six is what conditions the launch (SPEC 9.1), so
             the figure is never shown without it. --}}
        <x-mary-stat :title="__('Modérateurs (plancher : six)')" :value="$activeModerators" icon="o-shield-check"
                     :description="$activeModerators < 6 ? __('sous le plancher') : null"
                     :color="$activeModerators < 6 ? 'text-warning' : null" />

        <x-mary-stat :title="__('Contributeurs vérifiés')" :value="$activeContributors" icon="o-user-group" />
        <x-mary-stat :title="__('Articles publiés')" :value="$publishedArticles" icon="o-document-text" />
        <x-mary-stat :title="__('Fiches projet')" :value="$projectCount" icon="o-cube" />
        <x-mary-stat :title="__('Appels API ce mois')" :value="$apiCallsThisMonth" icon="o-signal" />
    </div>
</div>

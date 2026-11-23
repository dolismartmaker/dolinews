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
        {{-- The queue comes first and is one of the tiles that lead
             somewhere: it is a figure that calls for an action. --}}
        <a href="{{ route('admin.review.index') }}" class="block rounded-box transition hover:bg-base-200">
            <x-mary-stat :title="__('En file de revue')" :value="$pendingArticles" icon="o-inbox-arrow-down" :description="__('Ouvrir la file')" />
        </a>

        {{-- Another tile that leads somewhere: what is published and
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

        {{-- Not flagged: an announcement may concern its editor and no
             module (SPEC 5.3). The tile opens the filtered list, where a
             misfiled one is filed under its sheet. --}}
        <a href="{{ route('admin.articles.index', ['sans_fiche' => 1]) }}" class="block rounded-box transition hover:bg-base-200">
            <x-mary-stat :title="__('Annonces sans fiche')" :value="$articlesWithoutSheet" icon="o-link-slash" :description="__('Ouvrir la liste')" />
        </a>
        <x-mary-stat :title="__('Appels API ce mois')" :value="$apiCallsThisMonth" icon="o-signal" />
    </div>

    {{-- Accounts only: the generic feeds are read without one and are not
         counted. The count per sheet and per editor is in their lists,
         for the super admin (SPEC 6.4). --}}
    <x-mary-header :title="__('Abonnements')" :subtitle="__('Comptes actifs et ce qu\'ils suivent. Les flux génériques se lisent sans compte : ils ne sont pas comptés.')" size="text-xl" class="mt-8" separator />

    {{-- What they follow. --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-mary-stat :title="__('Comptes abonnés')" :value="$subscriptions['accounts']" icon="o-users" />
        <x-mary-stat :title="__('Fil entier')" :value="$subscriptions['wholeFeed']" icon="o-queue-list" />
        <x-mary-stat :title="__('Sécurité, tous projets')" :value="$subscriptions['security']" icon="o-shield-exclamation" />
        <x-mary-stat :title="__('Confirmations en attente')" :value="$subscriptions['pending']" icon="o-envelope-open" />
        <x-mary-stat :title="__('Suivis de fiches')" :value="$subscriptions['projectWatches']" icon="o-cube" />
        <x-mary-stat :title="__('Suivis d\'éditeurs')" :value="$subscriptions['editorWatches']" icon="o-building-office" />
    </div>

    {{-- How they receive it: the addresses each mail sweep goes to, then
         the personal feeds. --}}
    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-mary-stat :title="__('À chaque publication')" :value="$subscriptions['emails']['instant'] ?? 0" icon="o-envelope" :description="__('Courriels d\'abonnement')" />
        <x-mary-stat :title="__('Un résumé par jour')" :value="$subscriptions['emails']['daily'] ?? 0" icon="o-envelope" :description="__('Courriels d\'abonnement')" />
        <x-mary-stat :title="__('Un résumé par semaine')" :value="$subscriptions['emails']['weekly'] ?? 0" icon="o-envelope" :description="__('Courriels d\'abonnement')" />
        {{-- A token says the feed was issued, not that a reader still
             fetches it; nothing logs the fetches. --}}
        <x-mary-stat :title="__('Flux personnels')" :value="$subscriptions['feeds']" icon="o-rss" :description="__('émis, lecture non mesurée')" />
    </div>
</div>

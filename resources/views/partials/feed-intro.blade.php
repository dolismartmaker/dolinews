{{-- Presentation of the service at the head of the side column of the feed:
     what the feed holds, and the two delivery channels named in words
     (SPEC 6.4). --}}
{{-- Flat colours and a border, no blur and no animation: a
     decorated hero is repainted on every scroll step and the page
     crawls, Firefox first (LARAVEL_PAGES_PUBLIQUES 3). --}}
<section class="card">
    <div class="card-body">
        {{-- The review figures live on the transparency page, not
             here: a reader who comes for the feed has no use for
             the state of the queue, which concerns authors
             (SPEC 5.1). --}}
        <h1 class="text-xl font-semibold tracking-tight">
            {{ __('Annonces de l\'écosystème Dolibarr') }}
        </h1>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
            {{ __('Suivez toute l\'actualité à propos des modules et services proposés par les éditeurs de modules de Dolibarr.') }}
        </p>
        {{-- The two delivery channels in words and not in buttons
             alone: a button named "Recevoir par courriel" says what
             it does, never that one subscribes to a single project
             or editor rather than to the whole feed (SPEC 6.4).
             Naming the cadences answers the objection that comes
             next - whoever fears a mail a day takes the weekly
             summary instead of closing the page. --}}
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
            {{ __('Abonnez-vous à un projet ou à un éditeur pour recevoir un courriel dès qu\'une nouvelle version est annoncée, correctif de sécurité compris : à chaque publication, ou en un résumé par jour ou par semaine.') }}
        </p>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
            {{ __('Si vous utilisez un agrégateur de flux, le flux RSS porte les mêmes annonces.') }}
        </p>

        <div class="mt-4 flex flex-wrap gap-2">
            <a class="btn btn-sm btn-primary" href="{{ route('pages.editor-guide') }}">{{ __('Publier une annonce') }}</a>
            {{-- Ahead of the feeds, and not below them: whoever
                 runs a feed reader finds the RSS button on their
                 own, whoever does not would never have learnt
                 that the announcements can reach them at all. --}}
            <a class="btn btn-sm btn-outline" href="{{ auth()->check() ? route('account.show') : route('register') }}">{{ __('Recevoir par courriel') }}</a>
            <a class="btn btn-sm btn-outline" href="{{ route('feeds.rss', request()->query() + ['locale' => app()->getLocale()]) }}">{{ __('Flux RSS') }}</a>
            <a class="btn btn-sm btn-outline" href="{{ route('feeds.json', request()->query() + ['locale' => app()->getLocale()]) }}">{{ __('Flux JSON') }}</a>
        </div>
    </div>
</section>

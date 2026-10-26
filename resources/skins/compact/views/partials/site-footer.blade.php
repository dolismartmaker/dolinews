{{-- Footer of the compact skin: the same links as the default one, written
     as three lines of text instead of four columns. The review page and the
     source of the service stay in it (SPEC 5.1 and 11). --}}
<footer class="print-hidden border-t border-slate-200 dark:border-slate-800">
    <div class="mx-auto max-w-6xl space-y-1.5 px-4 py-4 text-xs text-slate-500 dark:text-slate-400">
        <p>
            <span class="font-bold text-slate-700 dark:text-slate-200">DoliNews</span>
            - {{ __('Annonces de l\'écosystème Dolibarr : ce qui a été annoncé, et quand.') }}
        </p>

        <p class="inline-links flex flex-wrap items-center">
            <a class="link" href="{{ route('feeds.rss', ['locale' => app()->getLocale()]) }}">{{ __('Flux RSS') }}</a>
            <a class="link" href="{{ route('feeds.json', ['locale' => app()->getLocale()]) }}">{{ __('Flux JSON') }}</a>
            <a class="link" href="{{ route('pages.api') }}">{{ __('API') }}</a>
            <a class="link" href="{{ route('pages.integrations') }}">{{ __('Intégrer le fil') }}</a>
            <a class="link" href="{{ route('pages.editor-guide') }}">{{ __('Guide de l\'éditeur') }}</a>
            <a class="link" href="{{ route('review.info') }}">{{ __('La revue') }}</a>
            <a class="link" href="{{ route('pages.rules') }}">{{ __('Règles d\'utilisation') }}</a>
        </p>

        <p class="inline-links flex flex-wrap items-center">
            <a class="link" href="{{ route('pages.commitments') }}">{{ __('Engagements publics') }}</a>
            <a class="link" href="{{ route('pages.data') }}">{{ __('Données personnelles') }}</a>
            <a class="link" href="{{ route('pages.legal') }}">{{ __('Mentions légales') }}</a>
            {{-- nofollow but no ugc: the service's own link, as in the
                 default footer. --}}
            <a class="link" href="{{ config('dolinews.source_url') }}" rel="nofollow">{{ __('Code source') }}</a>
        </p>

        <p>{{ __('Contenus publiés sous licence CC BY-SA 4.0, propriété de leurs auteurs. Code du service sous GNU AGPL v3.') }}</p>
    </div>
</footer>

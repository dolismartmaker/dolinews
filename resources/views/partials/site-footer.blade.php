{{-- Footer of the public layout: the feeds, publishing, the service, and
     the licences. --}}
<footer class="print-hidden border-t border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <div class="mx-auto max-w-6xl px-4 py-10">
        <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-sm font-semibold">DoliNews</p>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    {{-- The service says what was announced, and when; never
                         the current state of a module (SPEC D1). --}}
                    {{ __('Annonces de l\'écosystème Dolibarr : ce qui a été annoncé, et quand.') }}
                </p>
            </div>

            <div>
                <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase dark:text-slate-500">{{ __('Suivre') }}</p>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a class="link" href="{{ route('feeds.rss', ['locale' => app()->getLocale()]) }}">{{ __('Flux RSS') }}</a></li>
                    <li><a class="link" href="{{ route('feeds.json', ['locale' => app()->getLocale()]) }}">{{ __('Flux JSON') }}</a></li>
                    <li><a class="link" href="{{ route('pages.api') }}">{{ __('API') }}</a></li>
                    {{-- Next to the feeds rather than under "Publier":
                         integrating the feed into a site is reading, and
                         it needs no account (SPEC 6.4). --}}
                    <li><a class="link" href="{{ route('pages.integrations') }}">{{ __('Intégrer le fil') }}</a></li>
                </ul>
            </div>

            <div>
                <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase dark:text-slate-500">{{ __('Publier') }}</p>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a class="link" href="{{ route('pages.editor-guide') }}">{{ __('Guide de l\'éditeur') }}</a></li>
                    <li><a class="link" href="{{ route('review.info') }}">{{ __('La revue') }}</a></li>
                    <li><a class="link" href="{{ route('pages.rules') }}">{{ __('Règles d\'utilisation') }}</a></li>
                </ul>
            </div>

            <div>
                <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase dark:text-slate-500">{{ __('Le service') }}</p>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a class="link" href="{{ route('pages.commitments') }}">{{ __('Engagements publics') }}</a></li>
                    <li><a class="link" href="{{ route('pages.data') }}">{{ __('Données personnelles') }}</a></li>
                    <li><a class="link" href="{{ route('pages.legal') }}">{{ __('Mentions légales') }}</a></li>
                    {{-- The AGPL owes the source to whoever uses the
                         service (SPEC D13): the address belongs where the
                         licence is named, not in a README nobody opens.
                         nofollow but no ugc: this link is the service's
                         own, not content submitted by a third party. --}}
                    <li><a class="link" href="{{ config('dolinews.source_url') }}" rel="nofollow">{{ __('Code source') }}</a></li>
                </ul>
            </div>
        </div>

        <p class="mt-8 border-t border-slate-200 pt-6 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
            {{-- The licence covers the code alone: published contents stay
                 the property of their authors, under CC BY-SA 4.0. --}}
            {{ __('Contenus publiés sous licence CC BY-SA 4.0, propriété de leurs auteurs. Code du service sous GNU AGPL v3.') }}
        </p>
    </div>
</footer>

{{-- Footer of the airy skin: the same four columns and links as the default
     one, with no background and more room. The review page and the source of
     the service stay in it (SPEC 5.1 and 11). --}}
<footer class="print-hidden border-t border-slate-100 dark:border-slate-800/70">
    <div class="mx-auto max-w-6xl px-6 py-16">
        <div class="grid gap-12 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-base font-semibold text-slate-900 dark:text-white">DoliNews</p>
                <p class="mt-3 text-[15px] leading-7 text-slate-500 dark:text-slate-400">
                    {{ __('Annonces de l\'écosystème Dolibarr : ce qui a été annoncé, et quand.') }}
                </p>
            </div>

            <div>
                <p class="text-xs font-semibold tracking-[0.14em] text-slate-400 uppercase dark:text-slate-500">{{ __('Suivre') }}</p>
                <ul class="mt-4 space-y-3 text-[15px]">
                    <li><a class="link" href="{{ route('feeds.rss', ['locale' => app()->getLocale()]) }}">{{ __('Flux RSS') }}</a></li>
                    <li><a class="link" href="{{ route('feeds.json', ['locale' => app()->getLocale()]) }}">{{ __('Flux JSON') }}</a></li>
                    <li><a class="link" href="{{ route('pages.api') }}">{{ __('API') }}</a></li>
                    <li><a class="link" href="{{ route('pages.integrations') }}">{{ __('Intégrer le fil') }}</a></li>
                </ul>
            </div>

            <div>
                <p class="text-xs font-semibold tracking-[0.14em] text-slate-400 uppercase dark:text-slate-500">{{ __('Publier') }}</p>
                <ul class="mt-4 space-y-3 text-[15px]">
                    <li><a class="link" href="{{ route('pages.editor-guide') }}">{{ __('Guide de l\'éditeur') }}</a></li>
                    <li><a class="link" href="{{ route('review.info') }}">{{ __('La revue') }}</a></li>
                    <li><a class="link" href="{{ route('pages.rules') }}">{{ __('Règles d\'utilisation') }}</a></li>
                </ul>
            </div>

            <div>
                <p class="text-xs font-semibold tracking-[0.14em] text-slate-400 uppercase dark:text-slate-500">{{ __('Le service') }}</p>
                <ul class="mt-4 space-y-3 text-[15px]">
                    <li><a class="link" href="{{ route('pages.commitments') }}">{{ __('Engagements publics') }}</a></li>
                    <li><a class="link" href="{{ route('pages.data') }}">{{ __('Données personnelles') }}</a></li>
                    <li><a class="link" href="{{ route('pages.legal') }}">{{ __('Mentions légales') }}</a></li>
                    {{-- nofollow but no ugc: the service's own link, as in the
                         default footer. --}}
                    <li><a class="link" href="{{ config('dolinews.source_url') }}" rel="nofollow">{{ __('Code source') }}</a></li>
                </ul>
            </div>
        </div>

        <p class="mt-14 text-xs leading-6 text-slate-400 dark:text-slate-500">
            {{ __('Contenus publiés sous licence CC BY-SA 4.0, propriété de leurs auteurs. Code du service sous GNU AGPL v3.') }}
        </p>
    </div>
</footer>

{{-- Footer of the airy skin, which carries the navigation of the site: the
     sections first, the review page among them (SPEC 5.1 and 6.6), then the
     links the sections do not already name - the feeds, the personal data,
     the legal notices and the source of the service (SPEC 11) - and the
     licences. --}}
<footer class="print-hidden border-t border-slate-100 dark:border-slate-800/70">
    <div class="w-full px-6 py-14 lg:px-12">
        <nav class="flex flex-wrap items-center gap-x-8 gap-y-3 text-[17px]" aria-label="{{ __('Navigation principale') }}">
            @include('partials.public-nav', ['mode' => 'text'])
        </nav>

        <div class="mt-10 flex flex-wrap items-baseline gap-x-10 gap-y-4 text-[15px]">
            <p class="text-slate-500 dark:text-slate-400">
                <span class="font-semibold text-slate-900 dark:text-white">DoliNews</span>
                - {{ __('Annonces de l\'écosystème Dolibarr : ce qui a été annoncé, et quand.') }}
            </p>

            <ul class="flex flex-wrap gap-x-6 gap-y-2">
                <li><a class="link" href="{{ route('feeds.rss', ['locale' => app()->getLocale()]) }}">{{ __('Flux RSS') }}</a></li>
                <li><a class="link" href="{{ route('feeds.json', ['locale' => app()->getLocale()]) }}">{{ __('Flux JSON') }}</a></li>
                <li><a class="link" href="{{ route('pages.data') }}">{{ __('Données personnelles') }}</a></li>
                <li><a class="link" href="{{ route('pages.legal') }}">{{ __('Mentions légales') }}</a></li>
                {{-- nofollow but no ugc: the service's own link, as in the
                     default footer. --}}
                <li><a class="link" href="{{ config('dolinews.source_url') }}" rel="nofollow">{{ __('Code source') }}</a></li>
            </ul>
        </div>

        <p class="mt-10 text-xs leading-6 text-slate-400 dark:text-slate-500">
            {{ __('Contenus publiés sous licence CC BY-SA 4.0, propriété de leurs auteurs. Code du service sous GNU AGPL v3.') }}
        </p>
    </div>
</footer>

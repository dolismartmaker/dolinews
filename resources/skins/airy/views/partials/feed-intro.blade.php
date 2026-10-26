{{-- Presentation of the service in the airy skin: across the page, above
     the feed. The same sentences as the default one, the two delivery
     channels named in words (SPEC 6.4), the mail ahead of the feeds. Each
     paragraph keeps a reading width: the page is full width, a line of text
     is not. --}}
<section class="border-b border-slate-100 pb-10 dark:border-slate-800/70">
    <h1 class="max-w-4xl text-4xl leading-tight font-semibold tracking-tight text-slate-900 sm:text-5xl dark:text-white">
        {{ __('Annonces de l\'écosystème Dolibarr') }}
    </h1>

    <div class="mt-6 grid gap-x-12 gap-y-3 text-[17px] leading-8 text-slate-600 lg:grid-cols-3 dark:text-slate-300">
        <p class="max-w-prose">
            {{ __('Suivez toute l\'actualité à propos des modules et services proposés par les éditeurs de modules de Dolibarr.') }}
        </p>
        <p class="max-w-prose">
            {{ __('Abonnez-vous à un projet ou à un éditeur pour recevoir un courriel dès qu\'une nouvelle version est annoncée, correctif de sécurité compris : à chaque publication, ou en un résumé par jour ou par semaine.') }}
        </p>
        <p class="max-w-prose">
            {{ __('Si vous utilisez un agrégateur de flux, le flux RSS porte les mêmes annonces.') }}
        </p>
    </div>

    <div class="mt-8 flex flex-wrap gap-3">
        <a class="btn btn-primary" href="{{ route('pages.editor-guide') }}">{{ __('Publier une annonce') }}</a>
        <a class="btn btn-outline" href="{{ auth()->check() ? route('account.show') : route('register') }}">{{ __('Recevoir par courriel') }}</a>
        <a class="btn btn-outline" href="{{ route('feeds.rss', request()->query() + ['locale' => app()->getLocale()]) }}">{{ __('Flux RSS') }}</a>
        <a class="btn btn-outline" href="{{ route('feeds.json', request()->query() + ['locale' => app()->getLocale()]) }}">{{ __('Flux JSON') }}</a>
    </div>
</section>

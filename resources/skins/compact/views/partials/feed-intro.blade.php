{{-- Presentation of the service in the compact skin: the same sentences as
     the default one, the two delivery channels still named in words
     (SPEC 6.4), and the four actions as a line of links rather than a row of
     buttons. --}}
<section class="text-[13px] text-slate-600 dark:text-slate-300">
    <h1 class="text-base font-bold text-slate-900 dark:text-white">
        {{ __('Annonces de l\'écosystème Dolibarr') }}
    </h1>
    <p class="mt-1">
        {{ __('Suivez toute l\'actualité à propos des modules et services proposés par les éditeurs de modules de Dolibarr.') }}
    </p>
    <p class="mt-1">
        {{ __('Abonnez-vous à un projet ou à un éditeur pour recevoir un courriel dès qu\'une nouvelle version est annoncée, correctif de sécurité compris : à chaque publication, ou en un résumé par jour ou par semaine.') }}
    </p>
    <p class="mt-1">
        {{ __('Si vous utilisez un agrégateur de flux, le flux RSS porte les mêmes annonces.') }}
    </p>

    {{-- The mail ahead of the feeds, for the reason the default
         presentation gives. --}}
    <p class="inline-links mt-2 flex flex-wrap items-center">
        <a class="link font-bold" href="{{ route('pages.editor-guide') }}">{{ __('Publier une annonce') }}</a>
        <a class="link" href="{{ auth()->check() ? route('account.show') : route('register') }}">{{ __('Recevoir par courriel') }}</a>
        <a class="link" href="{{ route('feeds.rss', request()->query() + ['locale' => app()->getLocale()]) }}">{{ __('Flux RSS') }}</a>
        <a class="link" href="{{ route('feeds.json', request()->query() + ['locale' => app()->getLocale()]) }}">{{ __('Flux JSON') }}</a>
    </p>
</section>

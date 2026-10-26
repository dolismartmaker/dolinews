{{-- The feed with nothing to show, saying why (SPEC 6.1). --}}
<div class="card">
    <div class="card-body py-12 text-center">
        {{-- An empty search says WHY it is empty: a reader who
             reads "no result" concludes the service is empty,
             when the answer is that this editor does not
             publish here yet. The two are not the same, and
             only the second gives them something to do. --}}
        @if (($filters['search'] ?? null) !== null)
            <p class="text-slate-500 dark:text-slate-400">
                {{ __('Aucune annonce ne correspond à cette recherche.') }}
            </p>
            <p class="mt-2 text-slate-500 dark:text-slate-400">
                {{ __('Le module que vous cherchez existe peut-être sans que son éditeur publie ici : le service ne dit que ce qui lui a été annoncé.') }}
            </p>
            <p class="mt-2">
                <a class="link" href="{{ route('pages.editor-guide') }}">{{ __('Vous êtes cet éditeur ?') }}</a>
                <span class="text-slate-400 dark:text-slate-500">-</span>
                <a class="link" href="{{ route('home') }}">{{ __('Tout afficher') }}</a>
            </p>
        @elseif (request()->query())
            <p class="text-slate-500 dark:text-slate-400">{{ __('Aucune annonce ne correspond à ces filtres.') }}</p>
            <a class="link mt-2 inline-block" href="{{ route('home') }}">{{ __('Tout afficher') }}</a>
        @else
            {{-- No filter set and still nothing: the feed is
                 genuinely empty now, since an announcement
                 with no version in this language is shown in
                 its source language rather than hidden. --}}
            <p class="text-slate-500 dark:text-slate-400">{{ __('Aucune annonce publiée pour l\'instant.') }}</p>
        @endif
    </div>
</div>

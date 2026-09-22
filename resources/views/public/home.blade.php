@extends('layouts.public')

@section('title', __('Le fil'))

@section('content')
    {{-- Flat colours and a border, no blur and no animation: a decorated hero
         is repainted on every scroll step and the page crawls, Firefox first
         (LARAVEL_PAGES_PUBLIQUES 3). --}}
    <section class="card mb-6">
        <div class="card-body sm:p-8">
            <div class="lg:flex lg:items-end lg:justify-between lg:gap-8">
                <div class="max-w-2xl">
                    <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                        {{ __('Annonces de l\'écosystème Dolibarr') }}
                    </h1>
                    <p class="mt-3 text-base text-slate-600 dark:text-slate-300">
                        {{ __('Suivez toute l\'actualité à propos des modules et services proposés par les éditeurs de modules de Dolibarr.') }}
                    </p>

                    <div class="mt-5 flex flex-wrap gap-2">
                        <a class="btn btn-primary" href="{{ route('pages.editor-guide') }}">{{ __('Publier une annonce') }}</a>
                        <a class="btn btn-outline" href="{{ route('feeds.rss', request()->query() + ['locale' => app()->getLocale()]) }}">{{ __('Flux RSS') }}</a>
                        <a class="btn btn-outline" href="{{ route('feeds.json', request()->query() + ['locale' => app()->getLocale()]) }}">{{ __('Flux JSON') }}</a>
                    </div>
                </div>

                {{-- Observed figures, never a target: committing volunteers'
                     spare time would turn every hold-up into a breach
                     (SPEC 9.5). --}}
                <dl class="mt-8 grid grid-cols-2 gap-4 lg:mt-0 lg:w-80 lg:shrink-0">
                    <div class="stat">
                        <dd class="stat-value">{{ $medianSeconds !== null ? round($medianSeconds / 3600).' h' : '-' }}</dd>
                        <dt class="stat-label">{{ __('Délai observé (médiane)') }}</dt>
                    </div>
                    <div class="stat">
                        <dd class="stat-value">{{ $oldestPendingDays !== null ? $oldestPendingDays.' '.__('j') : '-' }}</dd>
                        <dt class="stat-label">{{ __('Attente la plus ancienne') }}</dt>
                    </div>
                </dl>
            </div>
        </div>
    </section>

    <form method="GET" action="{{ route('home') }}" class="card mb-6">
        <div class="card-body">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div class="form-control">
                    <label class="label" for="f-editor">{{ __('Éditeur') }}</label>
                    <input class="input" type="search" id="f-editor" name="editor" value="{{ $filters['editor'] ?? '' }}" placeholder="cap-rel">
                </div>

                <div class="form-control">
                    <label class="label" for="f-project">{{ __('Projet') }}</label>
                    <input class="input" type="search" id="f-project" name="project" value="{{ $filters['project'] ?? '' }}" placeholder="dolinews">
                </div>

                <div class="form-control">
                    <label class="label" for="f-dolibarr">{{ __('Concerne Dolibarr') }}</label>
                    {{-- The version filter targets announcements, never modules
                         (D1): the service never states the current state of a
                         module, only what was announced. --}}
                    <select class="input" id="f-dolibarr" name="dolibarr">
                        <option value="">{{ __('toutes versions') }}</option>
                        @foreach ($dolibarrMajors as $major)
                            <option value="{{ $major }}" @selected((int) ($filters['dolibarr'] ?? 0) === $major)>v{{ $major }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-control">
                    <label class="label" for="f-focus">{{ __('Focus') }}</label>
                    <select class="input" id="f-focus" name="focus">
                        <option value="">{{ __('tous') }}</option>
                        @foreach ($focusList as $focus)
                            <option value="{{ $focus->value }}" @selected(($filters['focus'] ?? null) === $focus->value)>{{ $focus->label() }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- No language filter here: the feed follows the
                     interface language, chosen once in the header. --}}
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                <button type="submit" class="btn btn-primary">{{ __('Filtrer') }}</button>
                @if (request()->query())
                    <a class="btn btn-ghost" href="{{ route('home') }}">{{ __('Tout afficher') }}</a>
                @endif
            </div>
        </div>
    </form>

    <div class="space-y-4">
        @forelse ($articles as $article)
            <article class="card overflow-hidden">
                <div class="card-body">
                    {{-- The date leaves the enumeration below for a flag of its
                         own, flush with the right edge of the card: a dated feed
                         is scanned by its dates, and buried in a row of labelled
                         fields they carry no more weight than the version. --}}
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold tracking-tight">
                                <a class="hover:text-accent-700 dark:hover:text-accent-300" href="{{ route('articles.show', $article) }}">
                                    {{ $article->title }}
                                </a>
                            </h2>

                            {{-- Labelled fields rather than a bare enumeration: the
                                 editor signs the announcement and answers for it, so
                                 it is named even when a project sheet is attached.
                                 Reading the project alone never says who published. --}}
                            {{-- Separator drawn by the stylesheet on every span but
                                 the first: written in the markup it had to follow
                                 each optional field, and the last one present left
                                 a bar hanging at the end of the line. --}}
                            <p class="meta-line mt-1 flex flex-col items-start gap-x-2 gap-y-1 text-sm text-slate-500 sm:flex-row sm:flex-wrap sm:items-center dark:text-slate-400">
                                @if ($article->project)
                                    <span>{{ __('Projet') }} :
                                        <a class="link" href="{{ route('projects.show', $article->project->slug) }}">{{ $article->project->name }}</a>
                                    </span>
                                @endif

                                @if ($article->editor)
                                    <span>{{ __('Éditeur') }} :
                                        <a class="link" href="{{ route('editors.show', $article->editor->slug) }}">{{ $article->editor->name }}</a>
                                    </span>
                                @endif

                                @if ($article->version)
                                    <span>{{ __('Version') }} : {{ $article->version }}</span>
                                @endif

                                @include('partials.dolibarr-range', ['article' => $article])
                            </p>
                        </div>

                        <div class="-mt-5 -mr-5 sm:-mt-6 sm:-mr-6">
                            @include('partials.date-flag', ['date' => $article->published_at])
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-1.5">
                        @if ($article->focus)
                            <span class="badge {{ $article->focus->value === 'security' ? 'badge-danger' : '' }}">{{ $article->focus->label() }}</span>
                        @endif

                        {{-- Maturity always WITH its age (SPEC 6.3): nobody ever
                             comes back to say a version left its test phase, so
                             the reader judges the pair alone. --}}
                        <span class="badge {{ in_array($article->maturity->value, ['alpha', 'beta', 'rc'], true) ? 'badge-warning' : ($article->maturity->value === 'deprecated' ? 'badge-neutral' : '') }}">
                            {{ $article->maturity->label() }}
                        </span>
                        @if ($article->published_at !== null)
                            <span class="badge">{{ __('annoncée il y a') }} {{ max(0, (int) round($article->published_at->diffInMonths(now()))) }} {{ __('mois') }}</span>
                        @endif

                        @if ($article->publication_mode?->value === 'bootstrap')
                            <span class="badge badge-info">{{ __('publié pendant l\'amorçage du service') }}</span>
                        @endif

                        {{-- No back-dating badge: an announcement carries
                             the date of the version it announces, and the
                             day it reached the service interests nobody
                             but the operator (SPEC 5.1). isBackdated()
                             still governs the figures the back-dating
                             would distort.

                             No language badge either: every announcement
                             here is in the language of the interface. --}}
                    </div>

                    <p class="mt-3 text-slate-700 dark:text-slate-200">{{ $article->summary }}</p>
                </div>

                {{-- No comment count next to the link: the service carries no
                     public comments, the discussion belongs to the Dolibarr
                     forum (SPEC D10). --}}
                <div class="card-footer">
                    <a class="link font-medium" href="{{ route('articles.show', $article) }}">
                        + {{ __('Lire la suite') }}
                    </a>
                </div>
            </article>
        @empty
            <div class="card">
                <div class="card-body py-12 text-center">
                    @if (request()->query())
                        <p class="text-slate-500 dark:text-slate-400">{{ __('Aucune annonce ne correspond à ces filtres.') }}</p>
                        <a class="link mt-2 inline-block" href="{{ route('home') }}">{{ __('Tout afficher') }}</a>
                    @else
                        {{-- Nothing here without a single filter set means
                             nothing is published in this language yet, not
                             that the service is empty: say which, and where
                             to change it. --}}
                        <p class="text-slate-500 dark:text-slate-400">
                            {{ __('Aucune annonce publiée dans cette langue pour l\'instant :') }}
                            {{ config('dolinews.locale_names.'.app()->getLocale(), strtoupper(app()->getLocale())) }}.
                        </p>
                        <p class="mt-2 text-slate-500 dark:text-slate-400">{{ __('Le fil suit la langue de l\'interface, qui se change en haut de page.') }}</p>
                    @endif
                </div>
            </div>
        @endforelse
    </div>

    <div class="mt-6">
        {{ $articles->links() }}
    </div>
@endsection

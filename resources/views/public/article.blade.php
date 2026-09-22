@extends('layouts.public')

@section('title', $article->title)
@section('description', $article->summary)

@section('content')
    <div class="mx-auto max-w-3xl">
        <article class="card">
            <div class="card-body sm:p-8">
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ $article->title }}</h1>

                {{-- Same labelled line as the feed, so the reader finds the
                     same fields in the same order once the article opens. --}}
                <p class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-500 dark:text-slate-400">
                    @if ($article->project)
                        <span>{{ __('Projet') }} :
                            <a class="link" href="{{ route('projects.show', $article->project->slug) }}">{{ $article->project->name }}</a>
                        </span>
                        <span aria-hidden="true">|</span>
                    @endif

                    @if ($article->editor)
                        <span>{{ __('Éditeur') }} :
                            <a class="link" href="{{ route('editors.show', $article->editor->slug) }}">{{ $article->editor->name }}</a>
                        </span>
                        <span aria-hidden="true">|</span>
                    @endif

                    @if ($article->version)
                        <span>{{ __('Version') }} : {{ $article->version }}</span>
                        <span aria-hidden="true">|</span>
                    @endif

                    <span>{{ __('Date') }} :
                        <time datetime="{{ $article->published_at?->toIso8601String() }}">{{ $article->published_at?->format('d/m/Y H:i') }}</time>
                    </span>

                    @if ($article->dolibarr_min !== null)
                        <span aria-hidden="true">|</span>
                        {{-- "annonces concernant la v22", never "modules
                             compatibles v22": the service says what was
                             announced, not the current state (SPEC D1). --}}
                        <span>{{ __('annonces concernant') }} Dolibarr v{{ $article->dolibarr_min }}{{ $article->dolibarr_max !== null ? ' -> v'.$article->dolibarr_max : '' }}</span>
                    @endif
                </p>

                <div class="mt-4 flex flex-wrap gap-1.5">
                    @if ($article->focus)
                        <span class="badge {{ $article->focus->value === 'security' ? 'badge-danger' : '' }}">{{ $article->focus->label() }}</span>
                    @endif

                    {{-- Maturity always paired with its age (SPEC 6.3). --}}
                    <span class="badge {{ in_array($article->maturity->value, ['alpha', 'beta', 'rc'], true) ? 'badge-warning' : ($article->maturity->value === 'deprecated' ? 'badge-neutral' : '') }}">
                        {{ $article->maturity->label() }}
                    </span>
                    <span class="badge">{{ __('annoncée il y a') }} {{ $maturityAgeMonths }} {{ __('mois') }}</span>

                    <span class="badge">{{ $article->compat_status->label() }}</span>
                    <span class="badge">{{ $article->locale }}</span>

                    @if ($article->publication_mode?->value === 'bootstrap')
                        <span class="badge badge-info">{{ __('publié pendant l\'amorçage du service, avant constitution de l\'équipe de modération') }}</span>
                    @endif

                    {{-- A back-dated article bears the date of the version it
                         announces and says nothing of the day it reached the
                         service: that gap is an operating detail, and the
                         reader has no use for it (SPEC 5.1). isBackdated()
                         still excludes the article from the figures the
                         back-dating would distort. --}}
                </div>

                {{-- A stale translation stays online but never silently poses as
                     current (SPEC 5.4): flagged, with a pointer to the source. --}}
                @if ($stale && $source !== null)
                    <div class="alert alert-warning mt-4">
                        {{ __('Cette traduction a été établie d\'après une version antérieure de l\'annonce.') }}
                        <a class="font-medium underline" href="{{ route('articles.show', $source) }}">{{ __('Lire la version d\'origine') }}</a>
                    </div>
                @endif

                {{-- Correction mention: a dated feed never rewrites its past
                     silently (SPEC 5.4). --}}
                @if ($correction !== null)
                    <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Corrigé le') }} {{ $correction->decided_at?->format('d/m/Y') }} - {{ $correction->motive }}
                    </p>
                @endif

                <div class="prose-dolinews mt-6">
                    {!! $bodyHtml !!}
                </div>

                @if (count($siblings) > 0)
                    <p class="print-hidden mt-8 border-t border-slate-100 pt-4 text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
                        {{ __('Autres langues :') }}
                        @foreach ($siblings as $sibling)
                            <a class="link" href="{{ route('articles.show', $sibling) }}">{{ $sibling->locale }}</a>@if (! $loop->last), @endif
                        @endforeach
                    </p>
                @endif
            </div>
        </article>

        <p class="print-hidden mt-6">
            <a class="link text-sm" href="{{ route('home') }}">{{ __('Retour au fil') }}</a>
        </p>
    </div>
@endsection

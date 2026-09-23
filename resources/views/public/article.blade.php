@extends('layouts.public')

@section('title', $article->title)
@section('description', $article->summary)

{{-- The language versions of the same announcement, declared to each
     other. Each one is a full article with its own address (SPEC D14):
     left unlinked, ten versions of one announcement compete instead of
     standing for one another, and the reader is served whichever the
     engine picked rather than the one in their language. The source
     version is the default for a language nobody asked for. --}}
@push('head')
    @foreach ($alternates as $tag => $href)
        <link rel="alternate" hreflang="{{ $tag }}" href="{{ $href }}">
    @endforeach
    @if ($sourceUrl !== null)
        <link rel="alternate" hreflang="x-default" href="{{ $sourceUrl }}">
    @endif
    @include('partials.json-ld', ['data' => $structuredData])
@endpush

@section('content')
    {{-- Two columns like a project sheet, and for the reason the sheet
         has them: the reader who has just understood that this
         announcement matters wants to follow what comes next, and
         sending them to the sheet first loses most of them (SPEC 6.4). --}}
    <div class="grid gap-6 lg:grid-cols-3 lg:items-start">
        <div class="lg:col-span-2">
        <article class="card">
            <div class="card-body sm:p-8">
                {{-- The language of the TEXT, which is not always the one of
                     the interface: an announcement the group carries in no
                     other version is served as it stands under every language
                     segment (SPEC 6.5). Inheriting lang="pl" over a French
                     body makes a screen reader pronounce French with Polish
                     rules, and tells a translation tool it has nothing to do.
                     The labelled line and the badges around it are interface,
                     and stay in the interface language. --}}
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl"
                    lang="{{ str_replace('_', '-', $article->locale) }}">{{ $article->title }}</h1>

                {{-- Same labelled line as the feed, so the reader finds the
                     same fields in the same order once the article opens, and
                     the same stylesheet-drawn separator: every field here is
                     optional too, and the Dolibarr range may render nothing. --}}
                <p class="meta-line mt-3 flex flex-col items-start gap-x-2 gap-y-1 text-sm text-slate-500 sm:flex-row sm:flex-wrap sm:items-center dark:text-slate-400">
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

                    {{-- The day, never the hour: the feed shows the day alone,
                         and the minute an announcement was accepted is review
                         work, not information about the version. The machine
                         keeps the full instant in the datetime attribute. --}}
                    <span>{{ __('Date') }} :
                        <time datetime="{{ $article->published_at?->toIso8601String() }}">{{ $article->published_at?->locale(app()->getLocale())->isoFormat('LL') }}</time>
                    </span>

                    @include('partials.dolibarr-range', ['article' => $article])
                </p>

                <div class="mt-4 flex flex-wrap gap-1.5">
                    @if ($article->focus)
                        <span class="badge {{ $article->focus->value === 'security' ? 'badge-danger' : '' }}">{{ $article->focus->label() }}</span>
                    @endif

                    {{-- Maturity always paired with its age (SPEC 6.3). --}}
                    <span class="badge {{ in_array($article->maturity->value, ['alpha', 'beta', 'rc'], true) ? 'badge-warning' : ($article->maturity->value === 'deprecated' ? 'badge-neutral' : '') }}">
                        {{ $article->maturity->label() }}
                    </span>
                    @if ($article->announcedAge() !== null)
                        <span class="badge">{{ $article->announcedAge() }}</span>
                    @endif

                    <span class="badge">{{ $article->compat_status->label() }}</span>

                    {{-- The language of the announcement, and only where it
                         tells the reader something: a raw "fr_FR" next to a
                         French text read as debugging left in place, while
                         the reader whose language the group does not carry
                         has to be told before the click (SPEC 6.1). --}}
                    @if (! str_starts_with($article->locale, substr(app()->getLocale(), 0, 2)))
                        <span class="badge">{{ __('en') }} {{ config('dolinews.locale_names.'.substr($article->locale, 0, 2), strtoupper(substr($article->locale, 0, 2))) }}</span>
                    @endif

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
                        <a class="font-medium underline" href="{{ \App\Domain\Dolinews\Seo\ArticleUrl::for($source) }}">{{ __('Lire la version d\'origine') }}</a>
                    </div>
                @endif

                {{-- Correction mention: a dated feed never rewrites its past
                     silently (SPEC 5.4). --}}
                @if ($correction !== null)
                    <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Corrigé le') }} {{ $correction->decided_at?->format('d/m/Y') }} - {{ $correction->motive }}
                    </p>
                @endif

                <div class="prose-dolinews mt-6" lang="{{ str_replace('_', '-', $article->locale) }}">
                    {!! $bodyHtml !!}
                </div>

                @if (count($siblings) > 0)
                    <p class="print-hidden mt-8 border-t border-slate-100 pt-4 text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
                        {{ __('Autres langues :') }}
                        @foreach ($siblings as $sibling)
                            <a class="link" href="{{ \App\Domain\Dolinews\Seo\ArticleUrl::for($sibling) }}">{{ config('dolinews.locale_names.'.substr($sibling->locale, 0, 2), $sibling->locale) }}</a>@if (! $loop->last), @endif
                        @endforeach
                    </p>
                @endif

                {{-- Shown to the editor and to whoever it mandated
                     (SPEC 5.6): a mandated translator reads the
                     announcement here and starts from here. --}}
                @if ($canTranslate)
                    <p class="print-hidden mt-4 text-sm">
                        <a class="link" href="{{ route('account.articles.translations.create', $article) }}">{{ __('Traduire cette annonce') }}</a>
                    </p>
                @endif
            </div>
        </article>

        {{-- The way back, and the way to say something is wrong with what
             was just read (SPEC 9.9). Discreet and at the end: the review
             happens before publication, so this is the exception, not the
             expected move. --}}
        <p class="print-hidden mt-6 flex flex-wrap gap-x-4 gap-y-1 text-sm">
            <a class="link" href="{{ route('home') }}">{{ __('Retour au fil') }}</a>
            <a class="link text-slate-500 dark:text-slate-400" href="{{ route('reports.article', $article) }}">{{ __('Signaler ce contenu') }}</a>
        </p>
        </div>

        <div class="print-hidden space-y-6">
            @if ($article->project)
                <div class="card">
                    <div class="card-body">
                        <h2 class="card-title">{{ __('Suivre ce projet') }}</h2>

                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                            {{ __('Recevoir un courriel dès qu\'une version de ce projet est annoncée, correctif de sécurité compris.') }}
                        </p>

                        @auth
                            <form method="POST" action="{{ route('watch.project', $article->project->getKey()) }}" class="mt-3 space-y-3">
                                @csrf
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" id="aw-security" name="focus[]" value="security">
                                    <span>{{ __('Correctifs de sécurité uniquement') }}</span>
                                </label>
                                <button type="submit" class="btn btn-primary w-full">{{ __('Suivre / ne plus suivre ce projet') }}</button>
                            </form>
                        @else
                            @include('partials.subscribe-form', [
                                'action' => route('subscribe.project', ['slug' => $article->project->slug]),
                                'checkboxId' => 'sub-article-project',
                                'fromArticle' => $article->getKey(),
                            ])
                        @endauth
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h2 class="card-title">{{ __('Le projet') }}</h2>

                        <div class="mt-3 flex items-start gap-3">
                            @if ($article->project->logo !== null)
                                <img class="h-10 w-10 shrink-0 rounded-lg border border-slate-200 object-contain dark:border-slate-700"
                                     src="{{ $article->project->logo->url() }}"
                                     alt="{{ $article->project->logo->alt ?? $article->project->name }}"
                                     loading="lazy">
                            @endif
                            <div>
                                <a class="link font-medium" href="{{ route('projects.show', $article->project->slug) }}">{{ $article->project->name }}</a>
                                @if ($article->editor)
                                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                        <a class="link" href="{{ route('editors.show', $article->editor->slug) }}">{{ $article->editor->name }}</a>
                                    </p>
                                @endif
                            </div>
                        </div>

                        <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">{{ $article->project->summary }}</p>

                        <p class="mt-4 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
                            <a class="link" href="{{ route('projects.show', $article->project->slug) }}">{{ __('Toutes les annonces du projet') }}</a>
                            -
                            <a class="link" href="{{ route('feeds.rss', ['project' => $article->project->slug]) }}">{{ __('Flux RSS') }}</a>
                        </p>
                    </div>
                </div>
            @elseif ($article->editor)
                {{-- An editor announcement carries no project sheet
                     (SPEC 4.3): the column follows the editor instead,
                     rather than standing empty where it is most
                     useful. --}}
                <div class="card">
                    <div class="card-body">
                        <h2 class="card-title">{{ __('Suivre cet éditeur') }}</h2>

                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                            {{ __('Recevoir un courriel dès qu\'une annonce de cet éditeur paraît, correctif de sécurité compris.') }}
                        </p>

                        @auth
                            <form method="POST" action="{{ route('watch.editor', $article->editor->getKey()) }}" class="mt-3 space-y-3">
                                @csrf
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" id="awe-security" name="focus[]" value="security">
                                    <span>{{ __('Correctifs de sécurité uniquement') }}</span>
                                </label>
                                <button type="submit" class="btn btn-primary w-full">{{ __('Suivre / ne plus suivre cet éditeur') }}</button>
                            </form>
                        @else
                            @include('partials.subscribe-form', [
                                'action' => route('subscribe.editor', ['slug' => $article->editor->slug]),
                                'checkboxId' => 'sub-article-editor',
                                'fromArticle' => $article->getKey(),
                            ])
                        @endauth
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h2 class="card-title">{{ __('L\'éditeur') }}</h2>
                        <p class="mt-3">
                            <a class="link font-medium" href="{{ route('editors.show', $article->editor->slug) }}">{{ $article->editor->name }}</a>
                        </p>
                        <p class="mt-3 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
                            <a class="link" href="{{ route('feeds.rss', ['editor' => $article->editor->slug]) }}">{{ __('Flux RSS') }}</a>
                        </p>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection

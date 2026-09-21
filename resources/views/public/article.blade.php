@extends('layouts.public')

@section('title', $article->title)

@section('content')
    <article class="card">
        <h1 style="font-size:1.3rem; margin:0 0 0.5rem">{{ $article->title }}</h1>

        <div class="meta" style="color:var(--muted); font-size:0.9rem; margin-bottom:0.75rem">
            @if ($article->project)
                <a href="{{ route('projects.show', $article->project->slug) }}">{{ $article->project->name }}</a>
            @else
                <a href="{{ route('editors.show', $article->editor?->slug ?? '') }}">{{ $article->editor?->name }}</a>
            @endif
            -
            {{ $article->published_at?->format('d/m/Y H:i') }}
            @if ($article->version) - v{{ $article->version }} @endif
            @if ($article->dolibarr_min !== null)
                - {{ __('annonces concernant') }} Dolibarr v{{ $article->dolibarr_min }}{{ $article->dolibarr_max !== null ? ' -> v'.$article->dolibarr_max : '' }}
            @endif
        </div>

        <div style="margin-bottom:0.75rem">
            @if ($article->focus)
                <span class="badge {{ $article->focus->value === 'security' ? 'security' : '' }}">{{ $article->focus->label() }}</span>
            @endif
            <span class="badge {{ $article->maturity->value }}">{{ $article->maturity->label() }}</span>
            <span class="badge">{{ __('annoncée il y a') }} {{ $maturityAgeMonths }} {{ __('mois') }}</span>
            <span class="badge">{{ $article->compat_status->label() }}</span>
            <span class="badge">{{ $article->locale }}</span>
            @if ($article->publication_mode?->value === 'bootstrap')
                <span class="badge bootstrap">{{ __('publié pendant l\'amorçage du service, avant constitution de l\'équipe de modération') }}</span>
            @endif
        </div>

        {{-- A stale translation stays online but never silently poses as
             current (SPEC 5.4): flagged, with a pointer to the source. --}}
        @if ($stale && $source !== null)
            <div class="flash" style="background:#fffbeb; border-color:#f59e0b; color:#92400e">
                {{ __('Cette traduction a été établie d\'après une version antérieure de l\'annonce.') }}
                <a href="{{ route('articles.show', $source) }}">{{ __('Lire la version d\'origine') }}</a>
            </div>
        @endif

        {{-- Correction mention: a dated feed never rewrites its past
             silently (SPEC 5.4). --}}
        @if ($correction !== null)
            <p class="meta" style="font-size:0.85rem; color:var(--muted)">
                {{ __('Corrigé le') }} {{ $correction->decided_at?->format('d/m/Y') }} - {{ $correction->motive }}
            </p>
        @endif

        <div class="article-body">
            {!! $bodyHtml !!}
        </div>

        @if (count($siblings) > 0)
            <p style="margin-top:1rem">
                {{ __('Autres langues :') }}
                @foreach ($siblings as $sibling)
                    <a href="{{ route('articles.show', $sibling) }}">{{ $sibling->locale }}</a>@if (! $loop->last), @endif
                @endforeach
            </p>
        @endif
    </article>
@endsection

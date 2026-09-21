@extends('layouts.public')

@section('title', __('Le fil'))

@section('content')
    <h1 style="font-size:1.3rem">{{ __('Annonces de l\'écosystème Dolibarr') }}</h1>
    <p class="meta" style="color:var(--muted)">
        {{ __('Ce service dit ce qui a été annoncé, et quand. Il ne dit jamais l\'état courant d\'un module.') }}
    </p>

    <div class="stats">
        <div class="stat">
            <div class="value">{{ $medianSeconds !== null ? round($medianSeconds / 3600).' h' : '-' }}</div>
            <div class="label">{{ __('Délai observé (médiane)') }}</div>
        </div>
        <div class="stat">
            <div class="value">{{ $oldestPendingDays !== null ? $oldestPendingDays.' '.__('j') : '-' }}</div>
            <div class="label">{{ __('Attente la plus ancienne') }}</div>
        </div>
    </div>

    <form method="GET" action="{{ route('home') }}" class="filterbar">
        <div>
            <label for="f-editor">{{ __('Éditeur') }}</label>
            <input type="search" id="f-editor" name="editor" value="{{ $filters['editor'] ?? '' }}" placeholder="cap-rel">
        </div>
        <div>
            <label for="f-project">{{ __('Projet') }}</label>
            <input type="search" id="f-project" name="project" value="{{ $filters['project'] ?? '' }}" placeholder="dolinews">
        </div>
        <div>
            <label for="f-dolibarr">{{ __('Concerne Dolibarr') }}</label>
            {{-- The version filter targets announcements, never modules (D1). --}}
            <select id="f-dolibarr" name="dolibarr">
                <option value="">{{ __('toutes versions') }}</option>
                @foreach ($dolibarrMajors as $major)
                    <option value="{{ $major }}" @selected((int) ($filters['dolibarr'] ?? 0) === $major)>v{{ $major }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-focus">{{ __('Focus') }}</label>
            <select id="f-focus" name="focus">
                <option value="">{{ __('tous') }}</option>
                @foreach ($focusList as $focus)
                    <option value="{{ $focus->value }}" @selected(($filters['focus'] ?? null) === $focus->value)>{{ $focus->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-locale">{{ __('Langue') }}</label>
            <input type="search" id="f-locale" name="locale" value="{{ $filters['locale'] ?? '' }}" placeholder="fr_FR">
        </div>
        <div>
            <input type="checkbox" id="f-all" name="all_maturities" value="1" @checked($includeUnstable)>
            <label for="f-all">{{ __('Inclure les maturités non stables') }}</label>
        </div>
        <button type="submit">{{ __('Filtrer') }}</button>
        <a class="btn btn-secondary" style="padding:0.45rem 0.9rem" href="{{ route('feeds.rss', request()->query()) }}">{{ __('RSS') }}</a>
        <a class="btn btn-secondary" style="padding:0.45rem 0.9rem" href="{{ route('feeds.json', request()->query()) }}">{{ __('JSON') }}</a>
    </form>

    @forelse ($articles as $article)
        <article class="entry">
            <h3>
                <a href="{{ route('articles.show', $article) }}">{{ $article->title }}</a>
            </h3>
            <div class="meta">
                @if ($article->project)
                    <a href="{{ route('projects.show', $article->project->slug) }}">{{ $article->project->name }}</a>
                @else
                    <a href="{{ route('editors.show', $article->editor?->slug ?? '') }}">{{ $article->editor?->name }}</a>
                @endif
                -
                {{ $article->published_at?->format('d/m/Y') }}
                @if ($article->version)
                    - v{{ $article->version }}
                @endif
                @if ($article->dolibarr_min !== null || $article->dolibarr_max !== null)
                    - Dolibarr
                    @if ($article->dolibarr_min !== null) v{{ $article->dolibarr_min }}@endif
                    @if ($article->dolibarr_min !== null && $article->dolibarr_max !== null) -> @endif
                    @if ($article->dolibarr_max !== null) v{{ $article->dolibarr_max }}@endif
                @endif
            </div>
            <div>
                @if ($article->focus)
                    <span class="badge {{ $article->focus->value === 'security' ? 'security' : '' }}">{{ $article->focus->label() }}</span>
                @endif
                {{-- Maturity always WITH its age (SPEC 6.3): nobody ever comes
                     back to say a version left its test phase, the reader
                     judges alone. --}}
                <span class="badge {{ $article->maturity->value }}">{{ $article->maturity->label() }}</span>
                @if ($article->published_at !== null)
                    <span class="badge">{{ __('annoncée il y a') }} {{ max(0, (int) round($article->published_at->diffInMonths(now()))) }} {{ __('mois') }}</span>
                @endif
                @if ($article->publication_mode?->value === 'bootstrap')
                    <span class="badge bootstrap">{{ __('publié pendant l\'amorçage du service') }}</span>
                @endif
                <span class="badge">{{ $article->locale }}</span>
            </div>
            <p class="summary" style="margin-top:0.5rem">{{ $article->summary }}</p>
        </article>
    @empty
        <p>{{ __('Aucune annonce ne correspond à ces filtres.') }}</p>
    @endforelse

    {{ $articles->links() }}
@endsection

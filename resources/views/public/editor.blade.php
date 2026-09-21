@extends('layouts.public')

@section('title', $editor->name)

@section('content')
    <div class="card">
        <h1 style="font-size:1.3rem; margin:0 0 0.35rem">
            {{ $editor->name }}
            @if ($editor->verified_at !== null)
                <span class="badge">{{ __('éditeur validé') }}</span>
            @endif
        </h1>

        @if ($editor->website)
            <p><a href="{{ $editor->website }}" rel="nofollow ugc">{{ $editor->website }}</a></p>
        @endif

        @if ($editor->description)
            <div class="article-body">
                <p>{{ $editor->description }}</p>
            </div>
        @endif
    </div>

    <div class="card">
        <h2>{{ __('Projets') }}</h2>
        <table class="plain">
            <tbody>
                @forelse ($projects as $project)
                    <tr>
                        <td><a href="{{ route('projects.show', $project->slug) }}">{{ $project->name }}</a></td>
                        <td>{{ $project->status->label() }}</td>
                    </tr>
                @empty
                    <tr><td>{{ __('Aucun projet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>{{ __('Annonces récentes') }}</h2>
        @forelse ($articles as $article)
            <article class="entry">
                <h3><a href="{{ route('articles.show', $article) }}">{{ $article->title }}</a></h3>
                <div class="meta">{{ $article->published_at?->format('d/m/Y') }}</div>
                <p class="summary">{{ $article->summary }}</p>
            </article>
        @empty
            <p>{{ __('Aucune annonce publiée.') }}</p>
        @endforelse
    </div>

    @auth
        <form method="POST" action="{{ route('watch.editor', $editor->getKey()) }}" class="stack">
            @csrf
            <div class="field checkbox-field">
                <input type="checkbox" id="we-security" name="focus[]" value="security">
                <label for="we-security">{{ __('Correctifs de sécurité uniquement') }}</label>
            </div>
            <button type="submit">{{ __('Suivre / ne plus suivre cet éditeur') }}</button>
        </form>
    @endauth
@endsection

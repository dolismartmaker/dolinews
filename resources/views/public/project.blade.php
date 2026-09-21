@extends('layouts.public')

@section('title', $project->name)

@section('content')
    <div class="card">
        <h1 style="font-size:1.3rem; margin:0 0 0.35rem">
            {{ $translation?->name ?? $project->name }}
            <span class="badge">{{ $project->status->label() }}</span>
            @if ($project->license)
                <span class="badge">{{ $project->license }}</span>
            @endif
        </h1>

        <p style="margin:0.25rem 0 0.5rem">
            <a href="{{ route('editors.show', $project->editor->slug) }}">{{ $project->editor->name }}</a>
            @if ($project->editor->verified_at !== null)
                <span class="badge">{{ __('éditeur validé') }}</span>
            @endif
        </p>

        <p>{{ $translation?->summary ?? $project->summary }}</p>

        {{-- The sheet carries NO Dolibarr compatibility (D1): what is
             dated lives in the announcements below. --}}
        @if ($translation?->description ?? $project->description)
            <div class="article-body">
                <p>{{ $translation->description ?? $project->description }}</p>
            </div>
        @endif

        @if ($project->translations->isNotEmpty())
            <p style="font-size:0.85rem">
                {{ __('Traductions de la fiche :') }}
                @foreach ($project->translations as $translationRow)
                    <a href="{{ route('projects.show', ['slug' => $project->slug, 'lang' => substr($translationRow->locale, 0, 2)]) }}">{{ $translationRow->locale }}</a>@if (! $loop->last), @endif
                @endforeach
            </p>
        @endif
    </div>

    <div class="card">
        <h2>{{ __('Liens') }}</h2>
        <ul style="margin:0; padding-left:1.1rem">
            @forelse ($project->links as $link)
                <li>
                    {{-- Outgoing links: nofollow ugc, no exception (D8). --}}
                    <a href="{{ $link->url }}" rel="nofollow ugc">{{ $link->label ?? $link->type->value }}</a>
                    @if ($link->is_broken)
                        <span class="badge">{{ __('lien cassé') }}</span>
                    @endif
                </li>
            @empty
                <li>{{ __('Aucun lien déclaré.') }}</li>
            @endforelse
        </ul>
    </div>

    @if ($attestations->isNotEmpty())
        <div class="card">
            <h2>{{ __('Tests publiés par l\'éditeur') }}</h2>
            {{-- Never "certifié", never "qualité": the instances are hosted
                 by the editors themselves, the indicator stays declarative
                 (SPEC 10). --}}
            <table class="plain">
                <thead>
                    <tr>
                        <th>{{ __('Indicateur') }}</th>
                        <th>{{ __('Valeur') }}</th>
                        <th>{{ __('Fraîcheur') }}</th>
                        <th>{{ __('Rapport') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($attestations as $attestation)
                        <tr>
                            <td>{{ $attestation->metric }}</td>
                            <td>{{ $attestation->value }}{{ $attestation->unit ? ' '.$attestation->unit : '' }}</td>
                            <td>{{ app(\App\Domain\Dolinews\Attestations\AttestationService::class)->freshness($attestation) }}</td>
                            <td><a href="{{ $attestation->source_url }}" rel="nofollow ugc">{{ __('détail') }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="card">
        <h2>{{ __('Annonces') }}</h2>
        @forelse ($articles as $article)
            <article class="entry">
                <h3><a href="{{ route('articles.show', $article) }}">{{ $article->title }}</a></h3>
                <div class="meta">{{ $article->published_at?->format('d/m/Y') }}</div>
                <p class="summary">{{ $article->summary }}</p>
            </article>
        @empty
            <p>{{ __('Aucune annonce publiée pour ce projet.') }}</p>
        @endforelse
    </div>

    @auth
        <form method="POST" action="{{ route('watch.project', $project->getKey()) }}" class="stack">
            @csrf
            <fieldset>
                <legend class="hint">{{ __('Filtres de l\'abonnement (facultatifs : par défaut, le fil du site)') }}</legend>
                <div class="field checkbox-field">
                    <input type="checkbox" id="w-security" name="focus[]" value="security">
                    <label for="w-security">{{ __('Correctifs de sécurité uniquement') }}</label>
                </div>
            </fieldset>
            <button type="submit">{{ __('Suivre / ne plus suivre ce projet') }}</button>
        </form>
    @endauth
@endsection

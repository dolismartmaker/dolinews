@extends('layouts.public')

@section('title', __('Mon compte'))

@section('content')
    <h1 style="font-size:1.3rem">{{ __('Mon compte') }}</h1>

    <div class="card">
        <h2>{{ __('Profil') }}</h2>
        <form method="POST" action="{{ route('account.update') }}" class="stack">
            @csrf

            <div class="field">
                <label for="name">{{ __('Nom') }}</label>
                <input id="name" type="text" name="name" value="{{ old('name', $user->name) }}" required maxlength="100">
            </div>

            <div class="field">
                <label for="display_name">{{ __('Nom public (facultatif)') }}</label>
                <input id="display_name" type="text" name="display_name" value="{{ old('display_name', $user->display_name) }}" maxlength="100">
            </div>

            <div class="field">
                <label for="website">{{ __('Site web (facultatif)') }}</label>
                <input id="website" type="url" name="website" value="{{ old('website', $user->website) }}">
            </div>

            <div class="field">
                <label for="bio">{{ __('Présentation (facultative)') }}</label>
                <textarea id="bio" name="bio" maxlength="2000">{{ old('bio', $user->bio) }}</textarea>
            </div>

            <button type="submit">{{ __('Enregistrer') }}</button>
        </form>
    </div>

    <div class="card">
        <h2>{{ __('Flux personnel') }}</h2>
        @if ($feedUrl)
            <p>{{ __('Votre flux personnel, en URL révocable :') }}</p>
            <div class="token-reveal">{{ $feedUrl }}</div>
            <form method="POST" action="{{ route('account.feed-token.regenerate') }}">
                @csrf
                <button type="submit" class="btn-danger">{{ __('Révoquer et régénérer') }}</button>
            </form>
        @else
            <p>{{ __('Un lecteur RSS ne sait pas s\'authentifier : votre abonnement se lit en URL à jeton révocable.') }}</p>
            <form method="POST" action="{{ route('account.feed-token') }}">
                @csrf
                <button type="submit">{{ __('Créer mon flux personnel') }}</button>
            </form>
        @endif
    </div>

    <div class="card">
        <h2>{{ __('Abonnements') }}</h2>

        <h3 style="font-size:0.95rem">{{ __('Projets suivis') }}</h3>
        <table class="plain">
            <tbody>
                @forelse ($projectWatches as $watch)
                    <tr>
                        <td>{{ $watch->project?->name }}</td>
                        <td>{{ $watch->focus_filter ? implode(', ', $watch->focus_filter) : __('filtres du site') }}</td>
                        <td>
                            <form method="POST" action="{{ route('watch.project', $watch->project_id) }}">
                                @csrf
                                <button type="submit" class="btn-secondary">{{ __('Ne plus suivre') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td>{{ __('Aucun projet suivi. Suivez un projet depuis sa fiche.') }}</td></tr>
                @endforelse
            </tbody>
        </table>

        <h3 style="font-size:0.95rem; margin-top:1rem">{{ __('Éditeurs suivis') }}</h3>
        <table class="plain">
            <tbody>
                @forelse ($editorWatches as $watch)
                    <tr>
                        <td>{{ $watch->editor?->name }}</td>
                        <td>{{ $watch->focus_filter ? implode(', ', $watch->focus_filter) : __('filtres du site') }}</td>
                        <td>
                            <form method="POST" action="{{ route('watch.editor', $watch->editor_id) }}">
                                @csrf
                                <button type="submit" class="btn-secondary">{{ __('Ne plus suivre') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td>{{ __('Aucun éditeur suivi. Suivez un éditeur depuis sa page.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p>
        <a class="btn btn-secondary" href="{{ route('account.contribute') }}">{{ __('Devenir contributeur') }}</a>
        <a class="btn btn-secondary" href="{{ route('account.articles') }}">{{ __('Mes articles') }}</a>
        <a class="btn btn-secondary" href="{{ route('account.tokens') }}">{{ __('Jetons d\'API') }}</a>
    </p>
@endsection

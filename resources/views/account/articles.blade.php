@extends('layouts.public')

@section('title', __('Mes articles'))

@section('content')
    <h1 style="font-size:1.3rem">{{ __('Mes articles') }}</h1>

    <p><a class="btn" href="{{ route('account.articles.create') }}">{{ __('Rédiger une annonce') }}</a></p>

    <table class="plain">
        <thead>
            <tr>
                <th>{{ __('Titre') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Langue') }}</th>
                <th>{{ __('Statut') }}</th>
                <th>{{ __('Soumis le') }}</th>
                <th>{{ __('Publié le') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($articles as $article)
                <tr>
                    <td>{{ $article->title }}</td>
                    <td>{{ $article->type->value }}</td>
                    <td>{{ $article->locale }}</td>
                    <td>{{ $article->status->value }}</td>
                    <td>{{ $article->submitted_at?->format('d/m/Y') }}</td>
                    <td>{{ $article->published_at?->format('d/m/Y') }}</td>
                    <td>
                        @if (in_array($article->status->value, ['draft', 'rejected', 'pending'], true))
                            <a href="{{ route('account.articles.edit', $article) }}">{{ __('Modifier') }}</a>
                        @endif
                        @if (in_array($article->status->value, ['draft', 'rejected'], true))
                            - <a href="{{ route('account.articles.edit', $article) }}#submit">{{ __('Soumettre') }}</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7">{{ __('Aucun article pour l\'instant.') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    {{ $articles->links() }}

    @if ($editors->isEmpty())
        <div class="card">
            <h2>{{ __('Créer un éditeur') }}</h2>
            <p>{{ __('On publie au nom d\'un éditeur. Créez le vôtre pour commencer : vous en serez le propriétaire.') }}</p>
            <form method="POST" action="{{ route('account.editors.store') }}" class="stack">
                @csrf
                <div class="field">
                    <label for="e-name">{{ __('Nom de l\'éditeur') }}</label>
                    <input id="e-name" type="text" name="name" required maxlength="150">
                </div>
                <div class="field">
                    <label for="e-contact">{{ __('Courriel de contact') }}</label>
                    <input id="e-contact" type="email" name="contact_email" required>
                </div>
                <div class="field">
                    <label for="e-website">{{ __('Site web (facultatif)') }}</label>
                    <input id="e-website" type="url" name="website">
                </div>
                <div class="field">
                    <label for="e-description">{{ __('Présentation (facultative)') }}</label>
                    <textarea id="e-description" name="description" maxlength="2000"></textarea>
                </div>
                <button type="submit">{{ __('Créer l\'éditeur') }}</button>
            </form>
        </div>
    @endif
@endsection

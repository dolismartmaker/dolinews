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
        @include('partials.editor-form', [
            'intro' => __('On publie au nom d\'un éditeur. Créez le vôtre pour commencer : vous en serez le propriétaire.'),
        ])
    @endif
@endsection

@extends('layouts.account')

@section('title', __('Mes articles'))

@section('account')
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold tracking-tight">{{ __('Mes articles') }}</h1>
        <a class="btn btn-primary" href="{{ route('account.articles.create') }}">{{ __('Rédiger une annonce') }}</a>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="overflow-x-auto">
                <table class="table-plain">
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
                                <td class="font-medium">{{ $article->title }}</td>
                                <td>{{ $article->type->value }}</td>
                                <td>{{ $article->locale }}</td>
                                <td><span class="badge">{{ $article->status->label() }}</span></td>
                                <td class="whitespace-nowrap">{{ $article->submitted_at?->format('d/m/Y') }}</td>
                                <td class="whitespace-nowrap">{{ $article->published_at?->format('d/m/Y') }}</td>
                                <td class="space-x-2 text-right whitespace-nowrap">
                                    {{-- A published article is never edited in place: the
                                         change is a revision, and it goes back through the
                                         review (SPEC 5.4). Hence no edit link here. --}}
                                    @if (in_array($article->status->value, ['draft', 'rejected', 'pending'], true))
                                        <a class="link" href="{{ route('account.articles.edit', $article) }}">{{ __('Modifier') }}</a>
                                    @endif
                                    @if (in_array($article->status->value, ['draft', 'rejected'], true))
                                        <a class="link" href="{{ route('account.articles.edit', $article) }}#submit">{{ __('Soumettre') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-8 text-center text-slate-500 dark:text-slate-400">
                                    {{ __('Aucun article pour l\'instant.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-4">
        {{ $articles->links() }}
    </div>

    @if ($editors->isEmpty())
        <div class="mt-6">
            @include('partials.editor-form', [
                'intro' => __('On publie au nom d\'un éditeur. Créez le vôtre pour commencer : vous en serez le propriétaire.'),
            ])
        </div>
    @endif
@endsection

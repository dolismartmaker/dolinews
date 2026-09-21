@extends('layouts.public')

@section('title', __('Mes projets'))

@section('content')
    <div class="mx-auto max-w-5xl">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight">{{ __('Mes projets') }}</h1>
            @if ($editors->isNotEmpty())
                <a class="btn btn-primary" href="{{ route('account.projects.create') }}">{{ __('Créer une fiche') }}</a>
            @endif
        </div>

        @include('partials.account-nav')

        {{-- The sheet is persistent and carries no Dolibarr compatibility
             (SPEC D1): what is dated lives on the article, with its date. --}}
        <p class="mb-6 max-w-3xl text-sm text-slate-500 dark:text-slate-400">
            {{ __('La fiche décrit votre projet de façon permanente : nom, résumé, licence, liens. Elle ne porte aucune compatibilité Dolibarr, qui vit sur l\'annonce avec sa date.') }}
        </p>

        @if ($editors->isEmpty())
            @include('partials.editor-form', [
                'intro' => __('Une fiche appartient à un éditeur. Créez le vôtre pour commencer : vous en serez le propriétaire.'),
            ])
        @else
            <div class="space-y-4">
                @forelse ($projects as $project)
                    <div class="card">
                        <div class="card-body">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h2 class="text-lg font-semibold tracking-tight">{{ $project->name }}</h2>
                                    <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-500 dark:text-slate-400">
                                        <span>{{ $project->editor?->name }}</span>
                                        <span aria-hidden="true">-</span>
                                        <code class="font-mono text-xs">{{ $project->slug }}</code>
                                        <span class="badge">{{ $project->status->label() }}</span>
                                        @if ($project->license)
                                            <span class="badge">{{ $project->license }}</span>
                                        @endif
                                    </p>
                                </div>

                                <div class="flex shrink-0 flex-wrap gap-2">
                                    <a class="btn btn-sm btn-outline" href="{{ route('projects.show', $project->slug) }}">{{ __('Voir la fiche publique') }}</a>
                                    <a class="btn btn-sm btn-primary" href="{{ route('account.projects.edit', $project) }}">{{ __('Modifier') }}</a>
                                </div>
                            </div>

                            <p class="mt-3 text-slate-700 dark:text-slate-200">{{ $project->summary }}</p>

                            <p class="mt-3 flex flex-wrap gap-1.5">
                                <span class="badge">{{ __('Liens') }} : {{ $project->links->count() }}</span>
                                <span class="badge">{{ __('Traductions') }} : {{ $project->translations->count() }}</span>
                            </p>
                        </div>
                    </div>
                @empty
                    <div class="card">
                        <div class="card-body py-12 text-center">
                            <p class="text-slate-500 dark:text-slate-400">{{ __('Aucune fiche pour l\'instant.') }}</p>
                            <a class="btn btn-primary mt-4" href="{{ route('account.projects.create') }}">{{ __('Créer une fiche') }}</a>
                        </div>
                    </div>
                @endforelse
            </div>
        @endif
    </div>
@endsection

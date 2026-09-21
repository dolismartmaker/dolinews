@extends('layouts.public')

@section('title', $editor->name)

@section('content')
    <div class="grid gap-6 lg:grid-cols-3 lg:items-start">
        <div class="space-y-6 lg:col-span-2">
            <div class="card">
                <div class="card-body sm:p-8">
                    <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ $editor->name }}</h1>

                    @if ($editor->verified_at !== null)
                        <p class="mt-2"><span class="badge">{{ __('éditeur validé') }}</span></p>
                    @endif

                    @if ($editor->website)
                        <p class="mt-3 text-sm">
                            {{-- nofollow ugc, no exception (D8). --}}
                            <a class="link break-words" href="{{ $editor->website }}" rel="nofollow ugc">{{ $editor->website }}</a>
                        </p>
                    @endif

                    @if ($editor->description)
                        <div class="prose-dolinews mt-4">
                            <p>{{ $editor->description }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">{{ __('Annonces récentes') }}</h2>
                    <div class="mt-3">
                        @forelse ($articles as $article)
                            @include('partials.article-teaser', ['article' => $article])
                        @empty
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Aucune annonce publiée.') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">{{ __('Projets') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @forelse ($projects as $project)
                            <li class="flex items-center justify-between gap-3">
                                <a class="link" href="{{ route('projects.show', $project->slug) }}">{{ $project->name }}</a>
                                <span class="badge shrink-0">{{ $project->status->label() }}</span>
                            </li>
                        @empty
                            <li class="text-slate-500 dark:text-slate-400">{{ __('Aucun projet.') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            @auth
                <div class="card">
                    <div class="card-body">
                        <h2 class="card-title">{{ __('Suivre cet éditeur') }}</h2>
                        <form method="POST" action="{{ route('watch.editor', $editor->getKey()) }}" class="mt-3 space-y-3">
                            @csrf
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" id="we-security" name="focus[]" value="security">
                                <span>{{ __('Correctifs de sécurité uniquement') }}</span>
                            </label>
                            <button type="submit" class="btn btn-primary w-full">{{ __('Suivre / ne plus suivre cet éditeur') }}</button>
                        </form>
                    </div>
                </div>
            @endauth
        </div>
    </div>
@endsection

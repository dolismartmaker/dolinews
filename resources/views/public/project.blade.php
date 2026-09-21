@extends('layouts.public')

@section('title', $project->name)
@section('description', $translation?->summary ?? $project->summary)

@section('content')
    <div class="grid gap-6 lg:grid-cols-3 lg:items-start">
        <div class="space-y-6 lg:col-span-2">
            <div class="card">
                <div class="card-body sm:p-8">
                    <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                        {{ $translation?->name ?? $project->name }}
                    </h1>

                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <span class="badge">{{ $project->status->label() }}</span>
                        @if ($project->license)
                            <span class="badge">{{ $project->license }}</span>
                        @endif
                    </div>

                    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
                        <a class="link" href="{{ route('editors.show', $project->editor->slug) }}">{{ $project->editor->name }}</a>
                        @if ($project->editor->verified_at !== null)
                            <span class="badge ml-1">{{ __('éditeur validé') }}</span>
                        @endif
                    </p>

                    <p class="mt-4 text-slate-700 dark:text-slate-200">{{ $translation?->summary ?? $project->summary }}</p>

                    {{-- The sheet carries NO Dolibarr compatibility (D1): it is
                         persistent, so any dated information it held would rot
                         without anyone correcting it. What is dated lives in the
                         announcements below. --}}
                    @if ($translation?->description ?? $project->description)
                        <div class="prose-dolinews mt-4">
                            <p>{{ $translation->description ?? $project->description }}</p>
                        </div>
                    @endif

                    @if ($project->translations->isNotEmpty())
                        <p class="mt-6 border-t border-slate-100 pt-4 text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
                            {{ __('Traductions de la fiche :') }}
                            @foreach ($project->translations as $translationRow)
                                <a class="link" href="{{ route('projects.show', ['slug' => $project->slug, 'lang' => substr($translationRow->locale, 0, 2)]) }}">{{ $translationRow->locale }}</a>@if (! $loop->last), @endif
                            @endforeach
                        </p>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">{{ __('Annonces') }}</h2>
                    <div class="mt-3">
                        @forelse ($articles as $article)
                            @include('partials.article-teaser', ['article' => $article])
                        @empty
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Aucune annonce publiée pour ce projet.') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">{{ __('Liens') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @forelse ($project->links as $link)
                            <li>
                                {{-- Outgoing links: nofollow ugc, no exception (D8). --}}
                                <a class="link break-words" href="{{ $link->url }}" rel="nofollow ugc">{{ $link->label ?? $link->type->value }}</a>
                                @if ($link->is_broken)
                                    <span class="badge badge-warning ml-1">{{ __('lien cassé') }}</span>
                                @endif
                            </li>
                        @empty
                            <li class="text-slate-500 dark:text-slate-400">{{ __('Aucun lien déclaré.') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            @if ($attestations->isNotEmpty())
                <div class="card">
                    <div class="card-body">
                        {{-- Never "certifié", never "qualité": the instances are
                             hosted by the editors themselves, so the indicator
                             stays declarative (SPEC 10). --}}
                        <h2 class="card-title">{{ __('Tests publiés par l\'éditeur') }}</h2>
                        <div class="mt-3 overflow-x-auto">
                            <table class="table-plain">
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
                                            <td><a class="link" href="{{ $attestation->source_url }}" rel="nofollow ugc">{{ __('détail') }}</a></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            @auth
                <div class="card">
                    <div class="card-body">
                        <h2 class="card-title">{{ __('Suivre ce projet') }}</h2>
                        <form method="POST" action="{{ route('watch.project', $project->getKey()) }}" class="mt-3 space-y-3">
                            @csrf
                            <p class="field-hint">{{ __('Filtres de l\'abonnement (facultatifs : par défaut, le fil du site)') }}</p>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" id="w-security" name="focus[]" value="security">
                                <span>{{ __('Correctifs de sécurité uniquement') }}</span>
                            </label>
                            <button type="submit" class="btn btn-primary w-full">{{ __('Suivre / ne plus suivre ce projet') }}</button>
                        </form>
                    </div>
                </div>
            @endauth
        </div>
    </div>
@endsection

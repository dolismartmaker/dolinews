@extends('layouts.public')

@section('title', $project->name)
@section('description', $translation?->summary ?? $project->summary)

@push('head')
    @include('partials.json-ld', ['data' => $structuredData])
    {{-- The feed of this sheet alone (SPEC 6.4): a reader landing on a
         module wants that module, and a reader whose browser or reader
         discovers feeds must find it here rather than the whole site's. --}}
    <link rel="alternate" type="application/rss+xml"
          title="{{ $project->name }} - DoliNews"
          href="{{ route('feeds.rss', ['project' => $project->slug]) }}">
@endpush

@section('content')
    <div class="grid gap-6 lg:grid-cols-3 lg:items-start">
        <div class="space-y-6 lg:col-span-2">
            <div class="card">
                <div class="card-body sm:p-8">
                    <div class="flex items-start gap-4">
                        {{-- The logo when the sheet carries one. It already
                             feeds the sharing card and the structured data of
                             this page; showing it costs nothing and gives a
                             wall of text one landmark. Nothing is promised by
                             its absence: most sheets have none. --}}
                        @if ($project->logo !== null)
                            <img class="h-14 w-14 shrink-0 rounded-lg border border-slate-200 object-contain dark:border-slate-700"
                                 src="{{ $project->logo->url() }}"
                                 alt="{{ $project->logo->alt ?? $project->name }}"
                                 loading="lazy">
                        @endif

                        <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                            {{ $translation?->name ?? $project->name }}
                        </h1>
                    </div>

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
                                <a class="link" href="{{ route('projects.show', ['slug' => $project->slug, 'lang' => substr($translationRow->locale, 0, 2)]) }}">{{ config('dolinews.locale_names.'.substr($translationRow->locale, 0, 2), $translationRow->locale) }}</a>@if (! $loop->last), @endif
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

            {{-- Claiming a sheet that is not yours is a case of its own
                 (SPEC 9.5) and shows on no announcement: the sheet needs
                 its own way to be reported. --}}
            <p class="print-hidden text-sm">
                <a class="link text-slate-500 dark:text-slate-400" href="{{ route('reports.project', $project->slug) }}">{{ __('Signaler cette fiche') }}</a>
            </p>

            {{-- Shown to everyone, not only to the signed-in reader. The
                 subscription is what serves the integrator who deploys
                 this module (SPEC 6.4), and the visitor who has just
                 read its sheet is exactly the one it exists for: hiding
                 the whole card behind @auth left them the site-wide feed
                 of the footer, which answers another question. --}}
            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">{{ __('Suivre ce projet') }}</h2>

                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                        {{ __('Recevoir un courriel dès qu\'une version de ce projet est annoncée, correctif de sécurité compris. Sans compte, le flux porte les mêmes annonces.') }}
                    </p>

                    @auth
                        <form method="POST" action="{{ route('watch.project', $project->getKey()) }}" class="mt-3 space-y-3">
                            @csrf
                            <p class="field-hint">{{ __('Filtres de l\'abonnement (facultatifs : par défaut, le fil du site)') }}</p>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" id="w-security" name="focus[]" value="security">
                                <span>{{ __('Correctifs de sécurité uniquement') }}</span>
                            </label>
                            <button type="submit" class="btn btn-primary w-full">{{ __('Suivre / ne plus suivre ce projet') }}</button>
                        </form>
                    @else
                        <div class="mt-3 flex flex-wrap gap-2">
                            <a class="btn btn-primary" href="{{ route('register') }}">{{ __('Créer un compte lecteur') }}</a>
                            <a class="btn btn-outline" href="{{ route('login') }}">{{ __('Connexion') }}</a>
                        </div>
                    @endauth

                    <p class="mt-4 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
                        <a class="link" href="{{ route('feeds.rss', ['project' => $project->slug]) }}">{{ __('Flux RSS') }}</a>
                        -
                        <a class="link" href="{{ route('feeds.json', ['project' => $project->slug]) }}">{{ __('Flux JSON') }}</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection

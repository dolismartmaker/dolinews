@extends('layouts.account')

@section('title', $project?->name ?? __('Créer une fiche'))

@section('account')
    <h1 class="mb-5 text-2xl font-semibold tracking-tight">
        {{ $project === null ? __('Créer une fiche') : $project->name }}
    </h1>

    <div class="space-y-6">
        <form method="POST"
              action="{{ $project === null ? route('account.projects.store') : route('account.projects.update', $project) }}"
              class="card">
            <div class="card-body space-y-4">
                @csrf
                @if ($project !== null)
                    @method('PATCH')
                @endif

                @if ($project === null)
                    <div class="form-control">
                        <label class="label" for="editor_id">{{ __('Éditeur propriétaire') }}</label>
                        <select class="input" id="editor_id" name="editor_id" required>
                            @foreach ($editors as $editor)
                                <option value="{{ $editor->getKey() }}" @selected((string) old('editor_id') === (string) $editor->getKey())>{{ $editor->name }}</option>
                            @endforeach
                        </select>
                        <p class="field-hint">{{ __('Le propriétaire ne change plus ensuite : un transfert de fiche passe par la modération.') }}</p>
                    </div>
                @else
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Éditeur :') }} {{ $project->editor?->name }}
                        <span aria-hidden="true">-</span>
                        <code class="font-mono text-xs">{{ $project->slug }}</code>
                    </p>
                @endif

                <div class="form-control">
                    <label class="label" for="name">{{ __('Nom du projet') }}</label>
                    <input class="input" id="name" type="text" name="name" value="{{ old('name', $project?->name) }}" required maxlength="150">
                </div>

                <div class="form-control">
                    <label class="label" for="summary">{{ __('Résumé') }}</label>
                    <input class="input" id="summary" type="text" name="summary" value="{{ old('summary', $project?->summary) }}" required maxlength="255">
                    <p class="field-hint">{{ __('Ce que fait le projet, en une phrase : c\'est ce que lit un visiteur avant d\'ouvrir la fiche.') }}</p>
                </div>

                <div class="form-control">
                    <label class="label" for="description">{{ __('Description (facultative)') }}</label>
                    <textarea class="input" id="description" name="description" rows="6">{{ old('description', $project?->description) }}</textarea>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="form-control">
                        <label class="label" for="license">{{ __('Licence (facultative)') }}</label>
                        <input class="input" id="license" type="text" name="license" value="{{ old('license', $project?->license) }}" maxlength="50" placeholder="GPL-3.0-or-later">
                    </div>

                    @if ($project !== null)
                        <div class="form-control">
                            <label class="label" for="status">{{ __('Statut') }}</label>
                            <select class="input" id="status" name="status">
                                @foreach (\App\Domain\Dolinews\Enums\ProjectStatus::cases() as $status)
                                    <option value="{{ $status->value }}" @selected(old('status', $project->status->value) === $status->value)>{{ $status->label() }}</option>
                                @endforeach
                            </select>
                            {{-- A sheet that says it is unmaintained stays
                                 honest; one that pretends otherwise rots. --}}
                            <p class="field-hint">{{ __('Une fiche qui dit qu\'elle n\'est plus maintenue reste juste.') }}</p>
                        </div>
                    @endif
                </div>

                {{-- No Dolibarr compatibility here, deliberately (SPEC D1). --}}
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ __('La compatibilité Dolibarr ne se déclare pas ici : elle appartient à l\'annonce, qui porte sa date.') }}
                </p>

                <button type="submit" class="btn btn-primary">
                    {{ $project === null ? __('Créer la fiche') : __('Enregistrer') }}
                </button>
            </div>
        </form>

        @if ($project !== null)
            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">{{ __('Liens') }}</h2>
                    {{-- Outgoing links are nofollow ugc without exception,
                         and URL shorteners are refused (D8). --}}
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Les raccourcisseurs d\'URL sont refusés, et une fiche Dolistore déjà revendiquée par un autre projet déclenche un conflit que la modération tranche.') }}
                    </p>

                    <ul class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse ($project->links as $link)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0">
                                <span class="min-w-0">
                                    <span class="badge">{{ $link->type->value }}</span>
                                    <a class="link ml-1 break-all" href="{{ $link->url }}" rel="nofollow ugc">{{ $link->label ?? $link->url }}</a>
                                    @if ($link->is_broken)
                                        <span class="badge badge-warning ml-1">{{ __('lien cassé') }}</span>
                                    @endif
                                </span>

                                <form method="POST" action="{{ route('account.projects.links.destroy', [$project, $link->getKey()]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline">{{ __('Retirer') }}</button>
                                </form>
                            </li>
                        @empty
                            <li class="py-3 text-sm text-slate-500 dark:text-slate-400">{{ __('Aucun lien déclaré.') }}</li>
                        @endforelse
                    </ul>

                    <form method="POST" action="{{ route('account.projects.links', $project) }}" class="mt-4 space-y-4">
                        @csrf

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="form-control">
                                <label class="label" for="link-type">{{ __('Type de lien') }}</label>
                                <select class="input" id="link-type" name="type" required>
                                    @foreach (\App\Domain\Dolinews\Enums\LinkType::cases() as $type)
                                        <option value="{{ $type->value }}">{{ $type->value }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="form-control">
                                <label class="label" for="link-label">{{ __('Intitulé (facultatif)') }}</label>
                                <input class="input" id="link-label" type="text" name="label" value="{{ old('label') }}" maxlength="255">
                            </div>
                        </div>

                        <div class="form-control">
                            <label class="label" for="link-url">{{ __('Adresse') }}</label>
                            <input class="input @error('url') input-error @enderror" id="link-url" type="url" name="url" value="{{ old('url') }}" required maxlength="2048" placeholder="https://">
                            @error('url') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        <button type="submit" class="btn btn-outline">{{ __('Ajouter le lien') }}</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">{{ __('Traductions de la fiche') }}</h2>
                    {{-- A sheet translation states nothing dated, so it does
                         not go through the review; a translated ARTICLE is
                         another matter entirely (SPEC 5.4). --}}
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Une traduction de fiche ne passe pas par la revue : elle ne dit rien de daté. Renvoyer la même langue remplace la traduction existante.') }}
                    </p>

                    <ul class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse ($project->translations as $translation)
                            <li class="py-3 first:pt-0">
                                <span class="badge">{{ $translation->locale }}</span>
                                <span class="ml-1 font-medium">{{ $translation->name }}</span>
                                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $translation->summary }}</p>
                            </li>
                        @empty
                            <li class="py-3 text-sm text-slate-500 dark:text-slate-400">{{ __('Aucune traduction.') }}</li>
                        @endforelse
                    </ul>

                    <form method="POST" action="{{ route('account.projects.translations', $project) }}" class="mt-4 space-y-4">
                        @csrf

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="form-control">
                                <label class="label" for="t-locale">{{ __('Langue') }}</label>
                                <input class="input" id="t-locale" type="text" name="locale" required maxlength="5" minlength="5" placeholder="en_US">
                            </div>

                            <div class="form-control">
                                <label class="label" for="t-name">{{ __('Nom traduit') }}</label>
                                <input class="input" id="t-name" type="text" name="name" required maxlength="150">
                            </div>
                        </div>

                        <div class="form-control">
                            <label class="label" for="t-summary">{{ __('Résumé traduit') }}</label>
                            <input class="input" id="t-summary" type="text" name="summary" required maxlength="255">
                        </div>

                        <div class="form-control">
                            <label class="label" for="t-description">{{ __('Description traduite (facultative)') }}</label>
                            <textarea class="input" id="t-description" name="description" rows="4"></textarea>
                        </div>

                        <button type="submit" class="btn btn-outline">{{ __('Enregistrer la traduction') }}</button>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endsection

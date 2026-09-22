@extends('layouts.account')

@section('title', __('Mon compte'))

@section('account')
    <h1 class="mb-5 text-2xl font-semibold tracking-tight">{{ __('Mon compte') }}</h1>

    <div class="space-y-6">
        <div class="card">
            <div class="card-body">
                <h2 class="card-title">{{ __('Profil') }}</h2>

                <form method="POST" action="{{ route('account.update') }}" class="mt-4 space-y-4">
                    @csrf

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="form-control">
                            <label class="label" for="name">{{ __('Nom') }}</label>
                            <input class="input" id="name" type="text" name="name" value="{{ old('name', $user->name) }}" required maxlength="100">
                        </div>

                        <div class="form-control">
                            <label class="label" for="display_name">{{ __('Nom public (facultatif)') }}</label>
                            <input class="input" id="display_name" type="text" name="display_name" value="{{ old('display_name', $user->display_name) }}" maxlength="100">
                        </div>
                    </div>

                    <div class="form-control">
                        <label class="label" for="website">{{ __('Site web (facultatif)') }}</label>
                        <input class="input" id="website" type="url" name="website" value="{{ old('website', $user->website) }}">
                    </div>

                    <div class="form-control">
                        <label class="label" for="bio">{{ __('Présentation (facultative)') }}</label>
                        <textarea class="input" id="bio" name="bio" rows="4" maxlength="2000">{{ old('bio', $user->bio) }}</textarea>
                    </div>

                    <button type="submit" class="btn btn-primary">{{ __('Enregistrer') }}</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="card-title">{{ __('Flux personnel') }}</h2>

                @if ($feedUrl)
                    <p class="mt-2 text-slate-700 dark:text-slate-200">{{ __('Votre flux personnel, en URL révocable :') }}</p>
                    <div class="code-block mt-3 break-all">{{ $feedUrl }}</div>
                    <form method="POST" action="{{ route('account.feed-token.regenerate') }}" class="mt-4">
                        @csrf
                        <button type="submit" class="btn btn-danger">{{ __('Révoquer et régénérer') }}</button>
                    </form>
                @else
                    {{-- A tokenised URL and never classic authentication: a
                         feed reader cannot authenticate (SPEC 7.3). --}}
                    <p class="mt-2 text-slate-700 dark:text-slate-200">{{ __('Un lecteur RSS ne sait pas s\'authentifier : votre abonnement se lit en URL à jeton révocable.') }}</p>
                    <form method="POST" action="{{ route('account.feed-token') }}" class="mt-4">
                        @csrf
                        <button type="submit" class="btn btn-primary">{{ __('Créer mon flux personnel') }}</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="card-title">{{ __('Recevoir les annonces par courriel') }}</h2>

                {{-- A feed reader reaches those who run one; the Dolibarr
                     user this is for does not run one, and an
                     announcement nobody is told about serves nobody
                     (SPEC 6.4). The cadence is the reader's: an
                     integrator wants a security fix within the hour, a
                     director wants one mail a week. --}}
                <form method="POST" action="{{ route('account.email') }}" class="mt-4 space-y-4">
                    @csrf

                    <fieldset class="space-y-2">
                        <legend class="label">{{ __('Fréquence') }}</legend>

                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="email_digest" value="none"
                                @checked($user->email_digest->value === 'none')>
                            <span>{{ __('Aucun courriel') }}</span>
                        </label>

                        @foreach ($digestChoices as $choice)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" name="email_digest" value="{{ $choice->value }}"
                                    @checked($user->email_digest === $choice)>
                                <span>
                                    @switch($choice->value)
                                        @case('instant')
                                            {{ __('À chaque publication') }}
                                            @break
                                        @case('daily')
                                            {{ __('Un résumé par jour') }}
                                            @break
                                        @default
                                            {{ __('Un résumé par semaine') }}
                                    @endswitch
                                </span>
                            </label>
                        @endforeach
                    </fieldset>

                    <div class="space-y-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <p class="field-hint">{{ __('Ce que le courriel contient : les projets et éditeurs suivis ci-dessous, et si vous le demandez, tout le fil.') }}</p>

                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="watches_all" value="1" @checked($user->watches_all)>
                            <span>{{ __('Toutes les annonces du fil') }}</span>
                        </label>

                        {{-- Following a module for its security fixes
                             alone is the most frequent need; an
                             unfiltered whole-feed watch drowns it, and
                             the reader unsubscribes. --}}
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="focus[]" value="security"
                                @checked(in_array('security', $user->watch_all_focus_filter ?? [], true))>
                            <span>{{ __('Correctifs de sécurité uniquement') }}</span>
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary">{{ __('Enregistrer') }}</button>
                </form>

                @if ($user->email_digest->value !== 'none')
                    <p class="mt-4 border-t border-slate-100 pt-4 text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
                        {{ __('Les courriels partent vers :') }} {{ $user->email }}.
                        {{ __('Chacun porte un lien pour les arrêter.') }}
                    </p>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="card-title">{{ __('Abonnements') }}</h2>

                <h3 class="mt-4 text-sm font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">{{ __('Projets suivis') }}</h3>
                <div class="mt-2 overflow-x-auto">
                    <table class="table-plain">
                        <tbody>
                            @forelse ($projectWatches as $watch)
                                <tr>
                                    <td class="font-medium">{{ $watch->project?->name }}</td>
                                    <td>{{ $watch->focus_filter ? implode(', ', $watch->focus_filter) : __('filtres du site') }}</td>
                                    <td class="text-right">
                                        <form method="POST" action="{{ route('watch.project', $watch->project_id) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline">{{ __('Ne plus suivre') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="text-slate-500 dark:text-slate-400">{{ __('Aucun projet suivi. Suivez un projet depuis sa fiche.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <h3 class="mt-6 text-sm font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">{{ __('Éditeurs suivis') }}</h3>
                <div class="mt-2 overflow-x-auto">
                    <table class="table-plain">
                        <tbody>
                            @forelse ($editorWatches as $watch)
                                <tr>
                                    <td class="font-medium">{{ $watch->editor?->name }}</td>
                                    <td>{{ $watch->focus_filter ? implode(', ', $watch->focus_filter) : __('filtres du site') }}</td>
                                    <td class="text-right">
                                        <form method="POST" action="{{ route('watch.editor', $watch->editor_id) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline">{{ __('Ne plus suivre') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="text-slate-500 dark:text-slate-400">{{ __('Aucun éditeur suivi. Suivez un éditeur depuis sa page.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

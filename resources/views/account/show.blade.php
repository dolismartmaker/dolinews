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

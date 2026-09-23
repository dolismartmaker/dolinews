@extends('layouts.public')

@section('title', __('Mes préférences d\'abonnement'))

{{-- The address carries a token, which is the whole authorisation
     (SPEC 6.4): it belongs in no index. --}}
@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
    <div class="mx-auto max-w-2xl space-y-6">
        @if (session('status'))
            <div class="rounded-lg border border-teal-200 bg-teal-50 p-3 text-sm text-teal-900 dark:border-teal-900 dark:bg-teal-950 dark:text-teal-100">
                {{ session('status') }}
            </div>
        @endif

        <div class="card">
            <div class="card-body sm:p-8">
                <h1 class="text-2xl font-semibold tracking-tight">{{ __('Mes préférences d\'abonnement') }}</h1>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ $user->email }}</p>

                <form method="POST" action="{{ route('subscriptions.preferences.update', ['token' => $token]) }}" class="mt-5 space-y-4">
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
                        <p class="field-hint">{{ __('Le courriel porte les projets et éditeurs suivis ci-dessous. Au-delà, vous pouvez y ajouter :') }}</p>

                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="watches_all" value="1" @checked($user->watches_all)>
                            <span>{{ __('Toutes les annonces du fil') }}</span>
                        </label>

                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="watches_all_security" value="1"
                                @checked($user->watches_all_security)>
                            <span>{{ __('Les correctifs de sécurité, quel que soit le projet') }}</span>
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary">{{ __('Enregistrer') }}</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body sm:p-8">
                <h2 class="card-title">{{ __('Ce que vous suivez') }}</h2>

                <h3 class="mt-4 text-sm font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">{{ __('Projets suivis') }}</h3>
                <div class="mt-2 overflow-x-auto">
                    <table class="table-plain">
                        <tbody>
                            @forelse ($projectWatches as $watch)
                                <tr>
                                    <td class="font-medium">{{ $watch->project?->name }}</td>
                                    <td>{{ $watch->focus_filter ? implode(', ', $watch->focus_filter) : __('filtres du site') }}</td>
                                    <td class="text-right">
                                        <form method="POST" action="{{ route('subscriptions.preferences.watch', ['token' => $token]) }}">
                                            @csrf
                                            <input type="hidden" name="project_id" value="{{ $watch->project_id }}">
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
                                        <form method="POST" action="{{ route('subscriptions.preferences.watch', ['token' => $token]) }}">
                                            @csrf
                                            <input type="hidden" name="editor_id" value="{{ $watch->editor_id }}">
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

                <p class="mt-5 border-t border-slate-100 pt-4 text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    {{ __('Ce lien expire ; pour revenir plus tard, demandez-en un nouveau depuis la page des préférences.') }}
                </p>
            </div>
        </div>
    </div>
@endsection

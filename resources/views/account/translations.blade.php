@extends('layouts.account')

@section('title', __('Mandats de traduction'))

@section('account')
    <h1 class="mb-5 text-2xl font-semibold tracking-tight">{{ __('Mandats de traduction') }}</h1>

    <div class="space-y-6">
        @if ($editor !== null)
            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">{{ __('Confier un mandat') }}</h2>
                    {{-- The mandate is neutral: it serves a volunteer of the
                         community and a translation desk alike (SPEC 5.6). --}}
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Un mandat autorise un autre compte contributeur à déposer les versions traduites de vos annonces. Elles paraissent sous votre nom, après relecture d\'un modérateur.') }}
                    </p>

                    <form method="POST" action="{{ route('account.translations.store') }}" class="mt-4 space-y-4">
                        @csrf
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="form-control">
                                <label class="label" for="translator_email">{{ __('Adresse du traducteur') }}</label>
                                <input class="input" id="translator_email" type="email" name="translator_email" required>
                                @error('translator_email')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="form-control">
                                <label class="label" for="project_id">{{ __('Portée') }}</label>
                                <select class="input" id="project_id" name="project_id">
                                    <option value="">{{ __('Tout le catalogue de l\'éditeur') }}</option>
                                    @foreach ($projects as $project)
                                        <option value="{{ $project->getKey() }}">{{ $project->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <fieldset class="form-control">
                            <legend class="label">{{ __('Langues confiées') }}</legend>
                            <p class="field-hint">{{ __('Aucune sélection vaut toutes les langues du service, celles d\'aujourd\'hui comme celles à venir.') }}</p>
                            <div class="mt-2 flex flex-wrap gap-3">
                                @foreach ($contentLocales as $locale)
                                    <label class="inline-flex items-center gap-2 text-sm">
                                        <input type="checkbox" name="locales[]" value="{{ $locale }}">
                                        <span>{{ $locale }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        <button type="submit" class="btn btn-primary">{{ __('Confier le mandat') }}</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">{{ __('Mandats confiés') }}</h2>
                    <div class="mt-3 overflow-x-auto">
                        <table class="table-plain">
                            <thead>
                                <tr>
                                    <th>{{ __('Traducteur') }}</th>
                                    <th>{{ __('Portée') }}</th>
                                    <th>{{ __('Langues') }}</th>
                                    <th>{{ __('Confié le') }}</th>
                                    <th>{{ __('État') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($granted as $mandate)
                                    <tr>
                                        <td class="font-medium">{{ $mandate->translator?->display_name ?? $mandate->translator?->name }}</td>
                                        <td>{{ $mandate->project?->name ?? __('Tout le catalogue') }}</td>
                                        <td>{{ $mandate->locales === null ? __('Toutes') : implode(', ', $mandate->locales) }}</td>
                                        <td class="whitespace-nowrap">{{ $mandate->granted_at?->format('d/m/Y') }}</td>
                                        <td>
                                            @if ($mandate->isInForce())
                                                <span class="badge">{{ __('en vigueur') }}</span>
                                            @else
                                                <span class="badge">{{ __('retiré le :date', ['date' => $mandate->revoked_at?->format('d/m/Y')]) }}</span>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            @if ($mandate->isInForce())
                                                <form method="POST" action="{{ route('account.translations.destroy', $mandate->getKey()) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-danger">{{ __('Retirer') }}</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="py-6 text-center text-slate-500 dark:text-slate-400">{{ __('Aucun mandat confié.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-body">
                <h2 class="card-title">{{ __('Mandats reçus') }}</h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Ce que d\'autres éditeurs vous ont confié. La traduction se dépose depuis la page de l\'annonce.') }}
                </p>
                <div class="mt-3 overflow-x-auto">
                    <table class="table-plain">
                        <thead>
                            <tr>
                                <th>{{ __('Éditeur') }}</th>
                                <th>{{ __('Portée') }}</th>
                                <th>{{ __('Langues') }}</th>
                                <th>{{ __('Confié le') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($held as $mandate)
                                <tr>
                                    <td class="font-medium">{{ $mandate->editor?->name }}</td>
                                    <td>{{ $mandate->project?->name ?? __('Tout le catalogue') }}</td>
                                    <td>{{ $mandate->locales === null ? __('Toutes') : implode(', ', $mandate->locales) }}</td>
                                    <td class="whitespace-nowrap">{{ $mandate->granted_at?->format('d/m/Y') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-6 text-center text-slate-500 dark:text-slate-400">{{ __('Aucun mandat reçu.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

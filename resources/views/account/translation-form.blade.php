@extends('layouts.account')

@section('title', __('Traduire cette annonce'))

@section('account')
    <h1 class="text-2xl font-semibold tracking-tight">{{ __('Traduire cette annonce') }}</h1>
    <p class="mt-1 mb-5 text-slate-700 dark:text-slate-200">{{ $source->title }}</p>

    <div class="card mb-6">
        <div class="card-body">
            <h2 class="card-title">{{ __('Les langues de cette annonce') }}</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                {{ __('Ce qui existe déjà, et ce qui manque. Une version produite par le service paraît à la date de l\'annonce, sans passer par la revue.') }}
            </p>

            @error('locale')
                <div class="alert alert-warning mt-4">{{ $message }}</div>
            @enderror

            <div class="mt-4 overflow-x-auto">
                <table class="table-plain">
                    <thead>
                        <tr>
                            <th>{{ __('Langue') }}</th>
                            <th>{{ __('Statut') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($contentLocales as $locale)
                            @php($version = $versions[$locale] ?? null)
                            <tr>
                                <td class="font-medium">{{ $locale }}@if ($locale === $source->locale) <span class="badge">{{ __('version d\'origine') }}</span>@endif</td>
                                <td>
                                    @if ($locale === $source->locale)
                                        <span class="badge">{{ $source->status->label() }}</span>
                                    @elseif ($version !== null)
                                        <span class="badge">{{ $version->status->label() }}</span>
                                    @else
                                        <span class="text-slate-500 dark:text-slate-400">{{ __('absente') }}</span>
                                    @endif
                                </td>
                                <td class="space-x-2 text-right whitespace-nowrap">
                                    @if ($version !== null && $version->status->value === 'published')
                                        <a class="link" href="{{ \App\Domain\Dolinews\Seo\ArticleUrl::for($version) }}">{{ __('Lire') }}</a>
                                    @endif

                                    {{-- Offered to the editor itself only: a machine
                                         version is published without review, which a
                                         mandated translator may not trigger. --}}
                                    @if ($version === null && $locale !== $source->locale && $canAskMachine)
                                        <form method="POST" action="{{ route('account.articles.translations.auto', $source) }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="locale" value="{{ $locale }}">
                                            <button type="submit" class="btn btn-sm btn-primary">{{ __('Traduire') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h2 class="card-title">{{ __('Écrire la traduction à la main') }}</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ $source->summary }}</p>

            {{-- A translation is an article in its own right: it keeps the
                 announcement's metadata and its date, and only the three
                 written fields are asked for here (SPEC 5.1/D14). --}}
            <form method="POST" action="{{ route('account.articles.translations', $source) }}" class="mt-5 space-y-4">
                @csrf

                <div class="form-control">
                    <label class="label" for="t-locale">{{ __('Langue de la traduction') }}</label>
                    <select class="input" id="t-locale" name="locale" required>
                        @foreach ($contentLocales as $locale)
                            @unless (in_array($locale, $existing, true))
                                <option value="{{ $locale }}">{{ $locale }}</option>
                            @endunless
                        @endforeach
                    </select>
                    @error('locale')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-control">
                    <label class="label" for="t-title">{{ __('Titre traduit') }}</label>
                    <input class="input" id="t-title" type="text" name="title" required maxlength="255" value="{{ old('title') }}">
                </div>

                <div class="form-control">
                    <label class="label" for="t-summary">{{ __('Résumé traduit') }}</label>
                    <input class="input" id="t-summary" type="text" name="summary" required maxlength="500" value="{{ old('summary') }}">
                </div>

                <div class="form-control">
                    <label class="label" for="t-body">{{ __('Corps traduit') }}</label>
                    <textarea class="input font-mono text-sm" id="t-body" name="body" rows="14" required maxlength="65535">{{ old('body') }}</textarea>
                    <p class="field-hint">{{ __('Le texte source reste consultable sur la page de l\'annonce.') }}</p>
                </div>

                <button type="submit" class="btn btn-primary">{{ __('Créer la traduction') }}</button>
            </form>
        </div>
    </div>

    <div class="mt-4">
        <a class="link" href="{{ \App\Domain\Dolinews\Seo\ArticleUrl::for($source) }}">{{ __('Voir l\'annonce d\'origine') }}</a>
    </div>
@endsection

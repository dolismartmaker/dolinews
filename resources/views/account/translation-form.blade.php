@extends('layouts.account')

@section('title', __('Traduire cette annonce'))

@section('account')
    <h1 class="mb-5 text-2xl font-semibold tracking-tight">{{ __('Traduire cette annonce') }}</h1>

    <div class="card">
        <div class="card-body">
            <h2 class="card-title">{{ $source->title }}</h2>
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
        <a class="link" href="{{ route('articles.show', $source) }}">{{ __('Voir l\'annonce d\'origine') }}</a>
    </div>
@endsection

@extends('layouts.public')

@section('title', $article?->title ?? __('Rédiger une annonce'))

@section('content')
    <h1 style="font-size:1.3rem">{{ $article === null ? __('Rédiger une annonce') : __('Modifier : '.$article->title) }}</h1>

    <form method="POST" action="{{ $article === null ? route('account.articles.store') : route('account.articles.update', $article) }}" class="stack">
        @csrf
        @if ($article !== null)
            @method('PATCH')
        @endif

        @if ($article === null)
            <div class="field">
                <label for="editor_id">{{ __('Publier au nom de') }}</label>
                <select id="editor_id" name="editor_id" required>
                    @foreach ($editors as $editor)
                        <option value="{{ $editor->getKey() }}">{{ $editor->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="field">
            <label for="type">{{ __('Type') }}</label>
            <select id="type" name="type" required>
                <option value="release" @selected(old('type', $article?->type?->value) === 'release')>{{ __('Publication de version') }}</option>
                <option value="announcement" @selected(old('type', $article?->type?->value) === 'announcement')>{{ __('Annonce') }}</option>
            </select>
        </div>

        <div class="field">
            <label for="project_id">{{ __('Projet (facultatif pour une annonce)') }}</label>
            <select id="project_id" name="project_id">
                <option value="">{{ __('aucun') }}</option>
                @foreach ($projects as $project)
                    <option value="{{ $project->getKey() }}" @selected((string) old('project_id', (string) $article?->project_id) === (string) $project->getKey())>{{ $project->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="title">{{ __('Titre') }}</label>
            <input id="title" type="text" name="title" value="{{ old('title', $article?->title) }}" required maxlength="255">
        </div>

        <div class="field">
            <label for="summary">{{ __('Résumé (obligatoire)') }}</label>
            <input id="summary" type="text" name="summary" value="{{ old('summary', $article?->summary) }}" required maxlength="500">
            <p class="hint">{{ __('C\'est ce qui rend le fil lisible en survol : ni journal des modifications brut, ni rien.') }}</p>
        </div>

        <div class="field">
            <label for="body">{{ __('Corps (Markdown, jamais de HTML libre)') }}</label>
            <textarea id="body" name="body" required maxlength="65535">{{ old('body', $article?->body) }}</textarea>
        </div>

        <div class="field">
            <label for="version">{{ __('Numéro de version (facultatif)') }}</label>
            <input id="version" type="text" name="version" value="{{ old('version', $article?->version) }}" maxlength="32">
        </div>

        <div class="field">
            <label for="locale">{{ __('Langue') }}</label>
            <input id="locale" type="text" name="locale" value="{{ old('locale', $article?->locale ?? 'fr_FR') }}" required maxlength="5">
        </div>

        <div class="field">
            <label for="focus">{{ __('Focus') }}</label>
            <select id="focus" name="focus">
                <option value="">{{ __('(sans, pour une annonce)') }}</option>
                @foreach (\App\Domain\Dolinews\Enums\Focus::cases() as $focus)
                    <option value="{{ $focus->value }}" @selected(old('focus', $article?->focus?->value) === $focus->value)>{{ $focus->label() }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="maturity">{{ __('Maturité') }}</label>
            <select id="maturity" name="maturity">
                @foreach (\App\Domain\Dolinews\Enums\Maturity::cases() as $maturity)
                    <option value="{{ $maturity->value }}" @selected(old('maturity', $article?->maturity->value ?? 'stable') === $maturity->value)>{{ $maturity->label() }}</option>
                @endforeach
            </select>
            <p class="hint">{{ __('Les maturités non stables sont exclues du fil par défaut.') }}</p>
        </div>

        <div class="field">
            <label for="compat_status">{{ __('Statut de compatibilité') }}</label>
            <select id="compat_status" name="compat_status">
                @foreach (\App\Domain\Dolinews\Enums\CompatStatus::cases() as $compat)
                    <option value="{{ $compat->value }}" @selected(old('compat_status', $article?->compat_status->value ?? 'declared') === $compat->value)>{{ $compat->label() }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="dolibarr_min">{{ __('Dolibarr minimum (version majeure, facultatif)') }}</label>
            <input id="dolibarr_min" type="number" name="dolibarr_min" min="1" max="99" value="{{ old('dolibarr_min', $article?->dolibarr_min) }}">
        </div>

        <div class="field">
            <label for="dolibarr_max">{{ __('Dolibarr maximum (version majeure, facultatif)') }}</label>
            <input id="dolibarr_max" type="number" name="dolibarr_max" min="1" max="99" value="{{ old('dolibarr_max', $article?->dolibarr_max) }}">
        </div>

        <button type="submit">{{ $article === null ? __('Créer le brouillon') : __('Enregistrer') }}</button>
    </form>

    @if ($article !== null)
        @if (in_array($article->status->value, ['draft', 'rejected'], true))
            <div class="card" id="submit">
                <h2>{{ __('Soumettre à la revue') }}</h2>
                <p class="hint">{{ __('La soumission réserve un jeton de publication. Trois accords de modérateurs publient automatiquement ; toute modification remet les accords à zéro.') }}</p>
                <form method="POST" action="{{ route('account.articles.submit', $article) }}">
                    @csrf
                    <button type="submit">{{ __('Soumettre') }}</button>
                </form>
            </div>
        @endif

        @if (in_array($article->status->value, ['published', 'hidden'], true))
            <div class="card">
                <h2>{{ __('Proposer une révision') }}</h2>
                <p class="hint">{{ __('Un article publié ne se modifie pas en place : la révision repasse par la revue, la version d\'origine reste consultable.') }}</p>
                <form method="POST" action="{{ route('account.articles.revisions', $article) }}" class="stack">
                    @csrf
                    <div class="field">
                        <label for="r-title">{{ __('Nouveau titre (facultatif)') }}</label>
                        <input id="r-title" type="text" name="title" maxlength="255">
                    </div>
                    <div class="field">
                        <label for="r-summary">{{ __('Nouveau résumé (facultatif)') }}</label>
                        <input id="r-summary" type="text" name="summary" maxlength="500">
                    </div>
                    <div class="field">
                        <label for="r-body">{{ __('Nouveau corps (facultatif)') }}</label>
                        <textarea id="r-body" name="body" maxlength="65535"></textarea>
                    </div>
                    <div class="field">
                        <label for="r-motive">{{ __('Motif (obligatoire)') }}</label>
                        <input id="r-motive" type="text" name="motive" required maxlength="255">
                    </div>
                    <button type="submit">{{ __('Proposer la révision') }}</button>
                </form>
            </div>
        @endif

        @if ($article->is_source && $article->status->value !== 'draft')
            <div class="card">
                <h2>{{ __('Traduire cette annonce') }}</h2>
                <form method="POST" action="{{ route('account.articles.translations', $article) }}" class="stack">
                    @csrf
                    <div class="field">
                        <label for="t-locale">{{ __('Langue de la traduction') }}</label>
                        <input id="t-locale" type="text" name="locale" required maxlength="5" placeholder="en_US">
                    </div>
                    <div class="field">
                        <label for="t-title">{{ __('Titre traduit') }}</label>
                        <input id="t-title" type="text" name="title" required maxlength="255">
                    </div>
                    <div class="field">
                        <label for="t-summary">{{ __('Résumé traduit') }}</label>
                        <input id="t-summary" type="text" name="summary" required maxlength="500">
                    </div>
                    <div class="field">
                        <label for="t-body">{{ __('Corps traduit') }}</label>
                        <textarea id="t-body" name="body" required maxlength="65535"></textarea>
                    </div>
                    <button type="submit">{{ __('Créer la traduction') }}</button>
                </form>
            </div>
        @endif
    @endif
@endsection

@extends('layouts.account')

@section('title', $article?->title ?? __('Rédiger une annonce'))

@section('account')
    <h1 class="mb-5 text-2xl font-semibold tracking-tight">
        {{ $article === null ? __('Rédiger une annonce') : __('Modifier : '.$article->title) }}
    </h1>

    <div class="space-y-6">
        <form method="POST" action="{{ $article === null ? route('account.articles.store') : route('account.articles.update', $article) }}" class="card">
            <div class="card-body space-y-4">
                @csrf
                @if ($article !== null)
                    @method('PATCH')
                @endif

                @if ($article === null)
                    <div class="form-control">
                        <label class="label" for="editor_id">{{ __('Publier au nom de') }}</label>
                        <select class="input" id="editor_id" name="editor_id" required>
                            @foreach ($editors as $editor)
                                <option value="{{ $editor->getKey() }}">{{ $editor->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="form-control">
                        <label class="label" for="type">{{ __('Type') }}</label>
                        <select class="input" id="type" name="type" required>
                            <option value="release" @selected(old('type', $article?->type?->value) === 'release')>{{ __('Publication de version') }}</option>
                            <option value="announcement" @selected(old('type', $article?->type?->value) === 'announcement')>{{ __('Annonce') }}</option>
                        </select>
                    </div>

                    <div class="form-control">
                        <label class="label" for="project_id">{{ __('Projet (facultatif pour une annonce)') }}</label>
                        <select class="input" id="project_id" name="project_id">
                            <option value="">{{ __('aucun') }}</option>
                            @foreach ($projects as $project)
                                <option value="{{ $project->getKey() }}" @selected((string) old('project_id', (string) $article?->project_id) === (string) $project->getKey())>{{ $project->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="form-control">
                    <label class="label" for="title">{{ __('Titre') }}</label>
                    <input class="input" id="title" type="text" name="title" value="{{ old('title', $article?->title) }}" required maxlength="255">
                </div>

                <div class="form-control">
                    <label class="label" for="summary">{{ __('Résumé (obligatoire)') }}</label>
                    <input class="input" id="summary" type="text" name="summary" value="{{ old('summary', $article?->summary) }}" required maxlength="500">
                    <p class="field-hint">{{ __('C\'est ce qui rend le fil lisible en survol : ni journal des modifications brut, ni rien.') }}</p>
                </div>

                <div class="form-control">
                    <label class="label" for="body">{{ __('Corps (Markdown, jamais de HTML libre)') }}</label>
                    <textarea class="input font-mono text-sm" id="body" name="body" rows="14" required maxlength="65535">{{ old('body', $article?->body) }}</textarea>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="form-control">
                        <label class="label" for="version">{{ __('Numéro de version (facultatif)') }}</label>
                        <input class="input" id="version" type="text" name="version" value="{{ old('version', $article?->version) }}" maxlength="32">
                    </div>

                    <div class="form-control">
                        <label class="label" for="locale">{{ __('Langue') }}</label>
                        <input class="input" id="locale" type="text" name="locale" value="{{ old('locale', $article?->locale ?? 'fr_FR') }}" required maxlength="5">
                    </div>

                    <div class="form-control">
                        <label class="label" for="focus">{{ __('Focus') }}</label>
                        <select class="input" id="focus" name="focus">
                            <option value="">{{ __('(sans, pour une annonce)') }}</option>
                            @foreach (\App\Domain\Dolinews\Enums\Focus::cases() as $focus)
                                <option value="{{ $focus->value }}" @selected(old('focus', $article?->focus?->value) === $focus->value)>{{ $focus->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-control">
                        <label class="label" for="maturity">{{ __('Maturité') }}</label>
                        <select class="input" id="maturity" name="maturity">
                            @foreach (\App\Domain\Dolinews\Enums\Maturity::cases() as $maturity)
                                <option value="{{ $maturity->value }}" @selected(old('maturity', $article?->maturity->value ?? 'stable') === $maturity->value)>{{ $maturity->label() }}</option>
                            @endforeach
                        </select>
                        <p class="field-hint">{{ __('Les maturités non stables sont exclues du fil par défaut.') }}</p>
                    </div>

                    <div class="form-control">
                        <label class="label" for="compat_status">{{ __('Statut de compatibilité') }}</label>
                        <select class="input" id="compat_status" name="compat_status">
                            @foreach (\App\Domain\Dolinews\Enums\CompatStatus::cases() as $compat)
                                <option value="{{ $compat->value }}" @selected(old('compat_status', $article?->compat_status->value ?? 'declared') === $compat->value)>{{ $compat->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Compatibility lives on the article, with its date, and
                     never on the project sheet (SPEC D1). --}}
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="form-control">
                        <label class="label" for="dolibarr_min">{{ __('Dolibarr minimum (version majeure, facultatif)') }}</label>
                        <input class="input" id="dolibarr_min" type="number" name="dolibarr_min" min="1" max="99" value="{{ old('dolibarr_min', $article?->dolibarr_min) }}">
                    </div>

                    <div class="form-control">
                        <label class="label" for="dolibarr_max">{{ __('Dolibarr maximum (version majeure, facultatif)') }}</label>
                        <input class="input" id="dolibarr_max" type="number" name="dolibarr_max" min="1" max="99" value="{{ old('dolibarr_max', $article?->dolibarr_max) }}">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">{{ $article === null ? __('Créer le brouillon') : __('Enregistrer') }}</button>
            </div>
        </form>

        @if ($article !== null)
            @if (in_array($article->status->value, ['draft', 'rejected'], true))
                <div class="card" id="submit">
                    <div class="card-body">
                        <h2 class="card-title">{{ __('Soumettre à la revue') }}</h2>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ __('La soumission réserve un jeton de publication. Trois accords de modérateurs publient automatiquement ; toute modification remet les accords à zéro.') }}</p>
                        {{-- Submitting is a declaration: rights held, CC BY-SA
                             4.0, no infringement (SPEC D15, rule R6). --}}
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ __('En soumettant, vous déclarez détenir les droits sur ce contenu, vous le placez sous licence CC BY-SA 4.0 et vous vous engagez à n\'enfreindre aucune loi ni aucun droit de tiers, notamment le droit des marques.') }}</p>

                        <form method="POST" action="{{ route('account.articles.submit', $article) }}" class="mt-4">
                            @csrf
                            <button type="submit" class="btn btn-primary">{{ __('Soumettre') }}</button>
                        </form>
                    </div>
                </div>
            @endif

            @if (in_array($article->status->value, ['published', 'hidden'], true))
                <div class="card">
                    <div class="card-body">
                        <h2 class="card-title">{{ __('Proposer une révision') }}</h2>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ __('Un article publié ne se modifie pas en place : la révision repasse par la revue, la version d\'origine reste consultable.') }}</p>

                        <form method="POST" action="{{ route('account.articles.revisions', $article) }}" class="mt-4 space-y-4">
                            @csrf

                            <div class="form-control">
                                <label class="label" for="r-title">{{ __('Nouveau titre (facultatif)') }}</label>
                                <input class="input" id="r-title" type="text" name="title" maxlength="255">
                            </div>

                            <div class="form-control">
                                <label class="label" for="r-summary">{{ __('Nouveau résumé (facultatif)') }}</label>
                                <input class="input" id="r-summary" type="text" name="summary" maxlength="500">
                            </div>

                            <div class="form-control">
                                <label class="label" for="r-body">{{ __('Nouveau corps (facultatif)') }}</label>
                                <textarea class="input font-mono text-sm" id="r-body" name="body" rows="10" maxlength="65535"></textarea>
                            </div>

                            <div class="form-control">
                                <label class="label" for="r-motive">{{ __('Motif (obligatoire)') }}</label>
                                <input class="input" id="r-motive" type="text" name="motive" required maxlength="255">
                            </div>

                            <button type="submit" class="btn btn-primary">{{ __('Proposer la révision') }}</button>
                        </form>
                    </div>
                </div>
            @endif

            @if ($article->is_source && $article->status->value !== 'draft')
                <div class="card">
                    <div class="card-body">
                        {{-- A translation is an article in its own right and
                             goes through the review too (SPEC 5.4). --}}
                        <h2 class="card-title">{{ __('Traduire cette annonce') }}</h2>

                        <form method="POST" action="{{ route('account.articles.translations', $article) }}" class="mt-4 space-y-4">
                            @csrf

                            <div class="form-control">
                                <label class="label" for="t-locale">{{ __('Langue de la traduction') }}</label>
                                <input class="input" id="t-locale" type="text" name="locale" required maxlength="5" placeholder="en_US">
                            </div>

                            <div class="form-control">
                                <label class="label" for="t-title">{{ __('Titre traduit') }}</label>
                                <input class="input" id="t-title" type="text" name="title" required maxlength="255">
                            </div>

                            <div class="form-control">
                                <label class="label" for="t-summary">{{ __('Résumé traduit') }}</label>
                                <input class="input" id="t-summary" type="text" name="summary" required maxlength="500">
                            </div>

                            <div class="form-control">
                                <label class="label" for="t-body">{{ __('Corps traduit') }}</label>
                                <textarea class="input font-mono text-sm" id="t-body" name="body" rows="10" required maxlength="65535"></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary">{{ __('Créer la traduction') }}</button>
                        </form>
                    </div>
                </div>
            @endif
        @endif
    </div>
@endsection

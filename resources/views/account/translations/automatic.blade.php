@extends('layouts.account')

@section('title', __('Traduction automatique'))

@section('account')
    <p class="mb-3 text-sm"><a class="link" href="{{ route('account.translations') }}">{{ __('Retour aux traductions') }}</a></p>

    <h1 class="mb-5 text-2xl font-semibold tracking-tight">{{ __('Traduction automatique') }}</h1>

    @if ($editor === null)
        <div class="card">
            <div class="card-body">
                <p>{{ __('Confier un mandat suppose de posséder un éditeur.') }}</p>
            </div>
        </div>
    @else
        <div class="space-y-6">
            @if ($engineOffered)
                <div class="card">
                    <div class="card-body">
                        <h2 class="card-title">{{ __('Traduire les langues manquantes') }}</h2>
                        {{-- Opt-in, never on by default: what comes out goes
                             out under the editor's name (SPEC 5.7). --}}
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                            {{ __('Le service traduit vos annonces publiées dans les langues qui leur manquent, gratuitement. Chaque version renvoie à l\'originale, et vous pouvez la corriger comme toute annonce.') }}
                        </p>
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                            {{ __('Une traduction écrite par une personne n\'est jamais remplacée, et les blocs de code de vos annonces ne sont pas traduits.') }}
                        </p>

                        <form method="POST" action="{{ route('account.translations.auto') }}" class="mt-4 flex flex-wrap items-center gap-3">
                            @csrf
                            <input type="hidden" name="auto_translate" value="{{ $editor->auto_translate ? '0' : '1' }}">
                            <span class="badge">{{ $editor->auto_translate ? __('activée') : __('désactivée') }}</span>
                            <button type="submit" class="btn btn-sm {{ $editor->auto_translate ? 'btn-danger' : 'btn-primary' }}">
                                {{ $editor->auto_translate ? __('Désactiver') : __('Activer') }}
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <div class="card">
                    <div class="card-body">
                        <p>{{ __('Cette possibilité n\'est pas ouverte sur ce service pour le moment.') }}</p>
                    </div>
                </div>
            @endif

            @if ($engineOffered)
                <div class="card">
                    <div class="card-body">
                        <h2 class="card-title">{{ __('Langues à produire') }}</h2>
                        {{-- An editor selling in two countries has no use for
                             eight versions nobody there reads, and only the
                             chosen languages draw on the allowance. --}}
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                            {{ __('Aucune sélection vaut toutes les langues du service. Retirer une langue n\'enlève rien de ce qui est déjà publié : les versions parues le restent, et elles continuent d\'être corrigées quand vous révisez l\'annonce d\'origine.') }}
                        </p>

                        <form method="POST" action="{{ route('account.translations.locales') }}" class="mt-4 space-y-4">
                            @csrf
                            <div class="flex flex-wrap gap-3">
                                @foreach ($contentLocales as $locale)
                                    <label class="inline-flex items-center gap-2 text-sm">
                                        <input type="checkbox" name="translation_locales[]" value="{{ $locale }}"
                                               @checked(in_array($locale, $wantedLocales, true))>
                                        <span>{{ $locale }}</span>
                                    </label>
                                @endforeach
                            </div>

                            <button type="submit" class="btn btn-primary">{{ __('Enregistrer les langues') }}</button>
                        </form>
                    </div>
                </div>
            @endif

            @unless ($hasOwnKey)
                <div class="card">
                    <div class="card-body">
                        <h2 class="card-title">{{ __('Volume du mois') }}</h2>
                        {{-- A ceiling shares a common resource between editors;
                             it sells nothing, and reaching it is a state to be
                             stated, never a breakage (SPEC 5.7/12). --}}
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                            {{ __('Le service traduit gratuitement dans la limite d\'un volume mensuel par éditeur, pour que la ressource reste partagée entre tous.') }}
                        </p>

                        <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                            <div>
                                <dt class="text-sm text-slate-500 dark:text-slate-400">{{ __('Volume du mois') }}</dt>
                                <dd class="stat-value">{{ number_format($ceiling, 0, ',', ' ') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-slate-500 dark:text-slate-400">{{ __('Déjà traduit') }}</dt>
                                <dd class="stat-value">{{ number_format($spent, 0, ',', ' ') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-slate-500 dark:text-slate-400">{{ __('Restant') }}</dt>
                                <dd class="stat-value">{{ number_format($remaining, 0, ',', ' ') }}</dd>
                            </div>
                        </dl>

                        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
                            {{ __('En caractères. Le volume est reconduit le') }} {{ $resetsOn->format('d/m/Y') }}.
                        </p>

                        @if ($remaining <= 0)
                            <div class="alert alert-warning mt-4">
                                {{ __('Le volume du mois est atteint : les prochaines annonces ne seront pas traduites automatiquement jusqu\'à sa reconduction. Vos traductions écrites à la main et celles de vos mandataires ne sont pas concernées.') }}
                            </div>
                        @endif
                    </div>
                </div>
            @endunless

            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">{{ __('Votre clé DeepL') }}</h2>
                    {{-- The way out past the ceiling: the editor holds an
                         account with a third party, pays that third party,
                         and owes the service nothing (SPEC 5.7/12). --}}
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Avec votre propre clé DeepL, vos annonces sont traduites sans limite de volume ici : vous réglez directement votre fournisseur, et le service ne vous facture rien. Un forfait gratuit existe chez DeepL.') }}
                    </p>

                    @if ($hasOwnKey)
                        <p class="mt-3">
                            <span class="badge">{{ __('Clé enregistrée le') }} {{ $editor->translation_key_set_at?->format('d/m/Y') }}</span>
                        </p>
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                            {{ __('La clé n\'est plus affichée. Enregistrez-en une nouvelle pour la remplacer, ou retirez-la pour repasser par le service.') }}
                        </p>
                    @endif

                    <form method="POST" action="{{ route('account.translations.key') }}" class="mt-4 flex flex-wrap items-end gap-3">
                        @csrf
                        <div class="form-control flex-1 sm:max-w-md">
                            <label class="label" for="translation_api_key">{{ __('Clé d\'API DeepL') }}</label>
                            <input class="input" id="translation_api_key" type="password" name="translation_api_key" autocomplete="off" maxlength="255">
                            @error('translation_api_key')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('Enregistrer') }}</button>
                    </form>

                    @if ($hasOwnKey)
                        <form method="POST" action="{{ route('account.translations.key') }}" class="mt-3">
                            @csrf
                            <input type="hidden" name="translation_api_key" value="">
                            <button type="submit" class="btn btn-sm btn-danger">{{ __('Retirer la clé') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @endif
@endsection

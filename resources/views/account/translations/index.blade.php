@extends('layouts.account')

@section('title', __('Traductions'))

@section('account')
    <h1 class="mb-2 text-2xl font-semibold tracking-tight">{{ __('Traductions') }}</h1>

    {{-- The two ways add up and are never a choice between them: an
         editor with a translator under mandate and the service filling in
         what is left is the normal case (SPEC 5.6/5.7). --}}
    <p class="mb-5 text-slate-700 dark:text-slate-200">
        {{ __('Une annonce publiée peut être traduite de deux façons, qui se cumulent : par une personne à qui vous en confiez le soin, et par le service pour les langues qui restent. Une traduction écrite par une personne n\'est jamais remplacée.') }}
    </p>

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="card">
            <div class="card-body">
                <h2 class="card-title">{{ __('Confier à une personne') }}</h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Vous autorisez un autre compte contributeur à déposer les versions traduites de vos annonces. Elles paraissent sous votre nom, après relecture d\'un modérateur.') }}
                </p>

                {{-- Plain __() and never trans_choice: the locale test walks
                     __() calls only, and a plural key it cannot see would
                     stay untranslated in nine languages without a word. --}}
                <p class="mt-3">
                    <span class="badge">{{ __('Mandats confiés :') }} {{ $grantedCount }}</span>
                    @if ($heldCount > 0)
                        <span class="badge">{{ __('Mandats reçus :') }} {{ $heldCount }}</span>
                    @endif
                </p>

                <p class="mt-4">
                    <a class="btn btn-primary" href="{{ route('account.translations.mandates') }}">{{ __('Gérer les mandats') }}</a>
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="card-title">{{ __('Laisser le service traduire') }}</h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Le service traduit vos annonces publiées dans les langues qui leur manquent, gratuitement. Chaque version renvoie à l\'originale, et vous pouvez la corriger comme toute annonce.') }}
                </p>

                @if ($engineOffered)
                    <p class="mt-3">
                        <span class="badge">{{ $autoTranslate ? __('activée') : __('désactivée') }}</span>
                    </p>

                    <p class="mt-4">
                        <a class="btn btn-primary" href="{{ route('account.translations.automatic') }}">{{ __('Régler la traduction automatique') }}</a>
                    </p>
                @else
                    {{-- Saying it plainly beats a button that leads to a
                         switch which would produce nothing. --}}
                    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Cette possibilité n\'est pas ouverte sur ce service pour le moment.') }}
                    </p>
                @endif
            </div>
        </div>
    </div>
@endsection

@extends('layouts.public')

@section('title', __('Signaler un contenu'))

@section('content')
    <div class="mx-auto max-w-xl">
        <div class="card">
            <div class="card-body sm:p-8">
                @if (session('reported'))
                    {{-- Deliberately vague on what follows: the team is a
                         volunteer one and no delay is ever announced
                         (SPEC 5.1). Saying "we will get back to you
                         shortly" would promise somebody else's spare
                         time. --}}
                    <h1 class="text-2xl font-semibold tracking-tight">{{ __('Signalement transmis') }}</h1>
                    <p class="mt-3 text-slate-700 dark:text-slate-200">
                        {{ __('L\'équipe de modération a été prévenue. Elle lit chaque signalement et décide de la suite ; aucun délai n\'est annoncé.') }}
                    </p>
                    <p class="mt-3 text-slate-600 dark:text-slate-300">
                        {{ __('Un signalement ne vaut pas manquement : un retrait ne se prend que sur une règle numérotée des règles d\'utilisation, et il est journalisé.') }}
                    </p>
                    <div class="mt-5 flex flex-wrap gap-2">
                        <a class="btn btn-outline" href="{{ $targetUrl }}">{{ __('Revenir au contenu') }}</a>
                        <a class="btn btn-outline" href="{{ route('pages.rules') }}">{{ __('Les règles d\'utilisation') }}</a>
                    </div>
                @else
                    <h1 class="text-2xl font-semibold tracking-tight">{{ __('Signaler un contenu') }}</h1>
                    <p class="mt-3 text-slate-700 dark:text-slate-200">
                        {{ __('Contenu signalé :') }}
                        <a class="link" href="{{ $targetUrl }}">{{ $targetLabel }}</a>
                    </p>
                    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Toute publication passe par une revue avant d\'être en ligne. La revue n\'est pas infaillible : ce formulaire prévient l\'équipe de modération, en privé. Il n\'y a pas de commentaires publics sur ce service.') }}
                    </p>

                    <form method="POST" action="{{ $action }}" class="mt-6 space-y-4">
                        @csrf

                        <div class="form-control">
                            <label class="label" for="report-reason">{{ __('Motif') }}</label>
                            <select id="report-reason" name="reason" class="input @error('reason') input-error @enderror">
                                @foreach ($reasons as $reason)
                                    <option value="{{ $reason->value }}" @selected(old('reason') === $reason->value)>{{ $reason->label() }}</option>
                                @endforeach
                            </select>
                            @error('reason') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="form-control">
                            <label class="label" for="report-body">{{ __('Ce qui pose problème') }}</label>
                            <textarea id="report-body" name="body" rows="5" class="input @error('body') input-error @enderror">{{ old('body') }}</textarea>
                            <p class="field-hint">{{ __('Décrivez précisément ce qui ne va pas : c\'est sur cette description que l\'équipe travaille.') }}</p>
                            @error('body') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="form-control">
                            <label class="label" for="report-email">{{ __('Votre adresse de courriel') }}</label>
                            <input id="report-email" name="email" type="email" class="input @error('email') input-error @enderror" value="{{ old('email', auth()->user()?->email) }}">
                            <p class="field-hint">{{ __('Obligatoire : l\'équipe doit pouvoir vous demander une précision. Elle n\'est jamais communiquée à l\'auteur du contenu.') }}</p>
                            @error('email') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        {{-- Bait field: hidden from a browser, filled by a bot.
                             aria-hidden and tabindex keep it out of a screen
                             reader's way, autocomplete off keeps a password
                             manager from filling it for a real visitor. --}}
                        <div class="hidden" aria-hidden="true">
                            <label for="report-website">{{ __('Ne pas remplir ce champ') }}</label>
                            <input id="report-website" name="website" type="text" value="" tabindex="-1" autocomplete="off">
                        </div>

                        <button type="submit" class="btn btn-primary">{{ __('Envoyer le signalement') }}</button>
                    </form>

                    <p class="mt-5 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Votre adresse et votre description sont conservées le temps du traitement et de son éventuelle contestation. Elles ne servent qu\'à cela.') }}
                    </p>
                @endif
            </div>
        </div>
    </div>
@endsection

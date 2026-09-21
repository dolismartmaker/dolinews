@extends('layouts.public')

@section('title', __('Ma contribution'))

@section('content')
    <div class="mx-auto max-w-4xl">
        <h1 class="mb-5 text-2xl font-semibold tracking-tight">{{ __('Devenir contributeur') }}</h1>

        @include('partials.account-nav')

        <div class="space-y-6">
            @if ($isContributor)
                <div class="alert alert-success">{{ __('Votre compte peut publier : il porte une preuve de contribution active.') }}</div>

                @if ($hasEditor)
                    <p class="text-slate-700 dark:text-slate-200">
                        {{ __('Vos annonces partent de l\'espace articles.') }}
                        <a class="link" href="{{ route('account.articles') }}">{{ __('Rédiger une annonce') }}</a>
                    </p>
                @else
                    {{-- The proof qualifies the account, it does not name who
                         publishes: without an editor, nothing can be submitted,
                         neither from the web nor from the API. --}}
                    @include('partials.editor-form', [
                        'intro' => __('Dernière étape : on ne publie jamais en son nom propre, mais au nom d\'un éditeur. Créez le vôtre, vous en serez le propriétaire. Si votre éditeur existe déjà ici, demandez plutôt à son propriétaire de vous y rattacher.'),
                    ])
                @endif
            @endif

            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">{{ __('Vérification de contribution') }}</h2>
                    {{-- The verification runs on a local git clone, never on a
                         forge API: a repository is distributed, and the check
                         must survive its host (D9). --}}
                    <p class="mt-2 text-slate-700 dark:text-slate-200">{{ __('Seuls les comptes contributeurs peuvent écrire. La vérification est une qualification, distincte de l\'authentification : votre adresse de commit est cherchée dans l\'index des auteurs des dépôts de référence, puis vous prouvez que l\'adresse vous appartient.') }}</p>

                    <form method="POST" action="{{ route('account.contribute.start') }}" class="mt-4 space-y-4">
                        @csrf

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="form-control">
                                <label class="label" for="commit_address">{{ __('Votre adresse d\'auteur de commits') }}</label>
                                <input class="input" id="commit_address" type="email" name="commit_address" value="{{ old('commit_address') }}" required>
                                <p class="field-hint">{{ __('L\'adresse n\'est jamais stockée en clair : uniquement son empreinte poivrée.') }}</p>
                            </div>

                            <div class="form-control">
                                <label class="label" for="method">{{ __('Niveau de vérification') }}</label>
                                <select class="input" id="method" name="method">
                                    <option value="email">{{ __('Simple : code à usage unique par courriel') }}</option>
                                    <option value="gpg">{{ __('Fort : défi signé avec votre clé GPG') }}</option>
                                </select>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">{{ __('Commencer la vérification') }}</button>
                    </form>
                </div>
            </div>

            @if (session('gpgChallenge'))
                <div class="card">
                    <div class="card-body">
                        <h2 class="card-title">{{ __('Défi GPG en attente') }}</h2>
                        <p class="mt-2 text-slate-700 dark:text-slate-200">{{ __('Signez exactement cette phrase avec la clé ayant signé vos commits, puis collez votre clé publique et la signature ASCII.') }}</p>
                        <div class="code-block mt-3 break-all">{{ session('gpgChallenge') }}</div>

                        <form method="POST" action="{{ route('account.contribute.gpg') }}" class="mt-4 space-y-4">
                            @csrf

                            <div class="form-control">
                                <label class="label" for="public_key">{{ __('Clé publique (bloc ASCII)') }}</label>
                                <textarea class="input font-mono text-xs" id="public_key" name="public_key" rows="6" required></textarea>
                            </div>

                            <div class="form-control">
                                <label class="label" for="signature">{{ __('Signature détachée du défi') }}</label>
                                <textarea class="input font-mono text-xs" id="signature" name="signature" rows="6" required></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary">{{ __('Vérifier la signature') }}</button>
                        </form>
                    </div>
                </div>
            @endif

            <div class="grid gap-6 lg:grid-cols-2">
                <div class="card">
                    <div class="card-body">
                        <h2 class="card-title">{{ __('Code reçu par courriel') }}</h2>
                        <form method="POST" action="{{ route('account.contribute.code') }}" class="mt-4 space-y-4">
                            @csrf
                            <div class="form-control">
                                <label class="label" for="code">{{ __('Code à usage unique') }}</label>
                                <input class="input font-mono" id="code" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" required maxlength="6">
                            </div>
                            <button type="submit" class="btn btn-primary">{{ __('Valider') }}</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        {{-- The way out when no reference repository knows the
                             address: without it, an editor with no public
                             repository reads a dead end (SPEC 3.3). --}}
                        <h2 class="card-title">{{ __('Validation manuelle') }}</h2>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ __('Éditeur sans dépôt public, ou adresse de redirection anonymisée ? L\'équipe de modération peut valider manuellement.') }}</p>

                        <form method="POST" action="{{ route('account.contribute.manual') }}" class="mt-4 space-y-4">
                            @csrf
                            <div class="form-control">
                                <label class="label" for="m-commit_address">{{ __('Adresse de commit concernée') }}</label>
                                <input class="input" id="m-commit_address" type="email" name="commit_address" required>
                            </div>
                            <div class="form-control">
                                <label class="label" for="explanation">{{ __('Justification') }}</label>
                                <textarea class="input" id="explanation" name="explanation" rows="3" required maxlength="2000"></textarea>
                            </div>
                            <button type="submit" class="btn btn-outline">{{ __('Demander la validation manuelle') }}</button>
                        </form>
                    </div>
                </div>
            </div>

            @if ($proofs->isNotEmpty())
                <div class="card">
                    <div class="card-body">
                        <h2 class="card-title">{{ __('Vos preuves') }}</h2>
                        <div class="mt-3 overflow-x-auto">
                            <table class="table-plain">
                                <thead>
                                    <tr>
                                        <th>{{ __('Méthode') }}</th>
                                        <th>{{ __('Dépôt') }}</th>
                                        <th>{{ __('Commits') }}</th>
                                        <th>{{ __('Vérifiée le') }}</th>
                                        <th>{{ __('État') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($proofs as $proof)
                                        <tr>
                                            <td>{{ $proof->method->value }}</td>
                                            <td class="break-all">{{ $proof->source_repo }}</td>
                                            <td>{{ $proof->commit_count }}</td>
                                            <td class="whitespace-nowrap">{{ $proof->verified_at->format('d/m/Y') }}</td>
                                            <td>
                                                @if ($proof->revoked_at === null)
                                                    <span class="badge badge-info">{{ __('active') }}</span>
                                                @else
                                                    <span class="badge badge-danger">{{ __('révoquée') }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection

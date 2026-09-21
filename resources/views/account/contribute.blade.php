@extends('layouts.public')

@section('title', __('Devenir contributeur'))

@section('content')
    <h1 style="font-size:1.3rem">{{ __('Devenir contributeur') }}</h1>

    @if ($isContributor)
        <div class="flash">{{ __('Votre compte peut publier : il porte une preuve de contribution active.') }}</div>

        @if ($hasEditor)
            <p>{{ __('Vos annonces partent de l\'espace articles.') }}
                <a href="{{ route('account.articles') }}">{{ __('Rédiger une annonce') }}</a></p>
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
        <h2>{{ __('Vérification de contribution') }}</h2>
        <p>{{ __('Seuls les comptes contributeurs peuvent écrire. La vérification est une qualification, distincte de l\'authentification : votre adresse de commit est cherchée dans l\'index des auteurs des dépôts de référence, puis vous prouvez que l\'adresse vous appartient.') }}</p>

        <form method="POST" action="{{ route('account.contribute.start') }}" class="stack">
            @csrf
            <div class="field">
                <label for="commit_address">{{ __('Votre adresse d\'auteur de commits') }}</label>
                <input id="commit_address" type="email" name="commit_address" value="{{ old('commit_address') }}" required>
                <p class="hint">{{ __('L\'adresse n\'est jamais stockée en clair : uniquement son empreinte poivrée.') }}</p>
            </div>
            <div class="field">
                <label for="method">{{ __('Niveau de vérification') }}</label>
                <select id="method" name="method">
                    <option value="email">{{ __('Simple : code à usage unique par courriel') }}</option>
                    <option value="gpg">{{ __('Fort : défi signé avec votre clé GPG') }}</option>
                </select>
            </div>
            <button type="submit">{{ __('Commencer la vérification') }}</button>
        </form>
    </div>

    @if (session('gpgChallenge'))
        <div class="card">
            <h2>{{ __('Défi GPG en attente') }}</h2>
            <p>{{ __('Signez exactement cette phrase avec la clé ayant signé vos commits, puis collez votre clé publique et la signature ASCII.') }}</p>
            <div class="token-reveal">{{ session('gpgChallenge') }}</div>
            <form method="POST" action="{{ route('account.contribute.gpg') }}" class="stack">
                @csrf
                <div class="field">
                    <label for="public_key">{{ __('Clé publique (bloc ASCII)') }}</label>
                    <textarea id="public_key" name="public_key" required></textarea>
                </div>
                <div class="field">
                    <label for="signature">{{ __('Signature détachée du défi') }}</label>
                    <textarea id="signature" name="signature" required></textarea>
                </div>
                <button type="submit">{{ __('Vérifier la signature') }}</button>
            </form>
        </div>
    @endif

    <div class="card">
        <h2>{{ __('Code reçu par courriel') }}</h2>
        <form method="POST" action="{{ route('account.contribute.code') }}" class="stack">
            @csrf
            <div class="field">
                <label for="code">{{ __('Code à usage unique') }}</label>
                <input id="code" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" required maxlength="6">
            </div>
            <button type="submit">{{ __('Valider') }}</button>
        </form>
    </div>

    <div class="card">
        <h2>{{ __('Validation manuelle') }}</h2>
        <p>{{ __('Éditeur sans dépôt public, ou adresse de redirection anonymisée ? L\'équipe de modération peut valider manuellement.') }}</p>
        <form method="POST" action="{{ route('account.contribute.manual') }}" class="stack">
            @csrf
            <div class="field">
                <label for="m-commit_address">{{ __('Adresse de commit concernée') }}</label>
                <input id="m-commit_address" type="email" name="commit_address" required>
            </div>
            <div class="field">
                <label for="explanation">{{ __('Justification') }}</label>
                <textarea id="explanation" name="explanation" required maxlength="2000"></textarea>
            </div>
            <button type="submit">{{ __('Demander la validation manuelle') }}</button>
        </form>
    </div>

    @if ($proofs->isNotEmpty())
        <div class="card">
            <h2>{{ __('Vos preuves') }}</h2>
            <table class="plain">
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
                            <td>{{ $proof->source_repo }}</td>
                            <td>{{ $proof->commit_count }}</td>
                            <td>{{ $proof->verified_at->format('d/m/Y') }}</td>
                            <td>{{ $proof->revoked_at === null ? __('active') : __('révoquée') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection

@extends('layouts.public')

@section('title', __('Jetons d\'API'))

@section('content')
    <h1 style="font-size:1.3rem">{{ __('Jetons d\'API') }}</h1>

    @if (session('newToken'))
        <div class="flash">
            {{ __('Copiez ce jeton maintenant : il ne sera plus affiché.') }}
            <div class="token-reveal" style="margin-top:0.5rem">{{ session('newToken') }}</div>
        </div>
    @endif

    <div class="card">
        <h2>{{ __('Créer un jeton') }}</h2>
        <p class="hint">
            {{ __('Destiné à une chaîne d\'intégration. Le jeton donne le droit de soumettre, jamais celui de publier.') }}
            <a href="{{ route('pages.api') }}">{{ __('Documentation de l\'API') }}</a>
        </p>
        <form method="POST" action="{{ route('account.tokens.store') }}" class="stack">
            @csrf
            <div class="field">
                <label for="name">{{ __('Nom du jeton') }}</label>
                <input id="name" type="text" name="name" required maxlength="100">
            </div>
            <button type="submit">{{ __('Créer') }}</button>
        </form>
    </div>

    <table class="plain">
        <thead>
            <tr>
                <th>{{ __('Nom') }}</th>
                <th>{{ __('Créé le') }}</th>
                <th>{{ __('Dernier usage') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($tokens as $token)
                <tr>
                    <td>{{ $token->name }}</td>
                    <td>{{ $token->created_at?->format('d/m/Y H:i') }}</td>
                    <td>{{ $token->last_used_at?->format('d/m/Y H:i') ?? __('jamais') }}</td>
                    <td>
                        <form method="POST" action="{{ route('account.tokens.destroy', $token->getKey()) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-danger">{{ __('Révoquer') }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4">{{ __('Aucun jeton.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection

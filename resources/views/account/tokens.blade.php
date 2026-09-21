@extends('layouts.public')

@section('title', __('Jetons d\'API'))

@section('content')
    <div class="mx-auto max-w-4xl">
        <h1 class="mb-5 text-2xl font-semibold tracking-tight">{{ __('Jetons d\'API') }}</h1>

        @include('partials.account-nav')

        @if (session('newToken'))
            <div class="alert alert-success mb-6">
                <p class="font-medium">{{ __('Copiez ce jeton maintenant : il ne sera plus affiché.') }}</p>
                <div class="code-block mt-3 break-all">{{ session('newToken') }}</div>
            </div>
        @endif

        <div class="space-y-6">
            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">{{ __('Créer un jeton') }}</h2>
                    {{-- A token grants the right to submit, never to publish
                         (SPEC 5.2). --}}
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('Destiné à une chaîne d\'intégration. Le jeton donne le droit de soumettre, jamais celui de publier.') }}
                        <a class="link" href="{{ route('pages.api') }}">{{ __('Documentation de l\'API') }}</a>
                    </p>

                    <form method="POST" action="{{ route('account.tokens.store') }}" class="mt-4 flex flex-wrap items-end gap-3">
                        @csrf
                        <div class="form-control flex-1 sm:max-w-xs">
                            <label class="label" for="name">{{ __('Nom du jeton') }}</label>
                            <input class="input" id="name" type="text" name="name" required maxlength="100" placeholder="{{ __('chaîne d\'intégration') }}">
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('Créer') }}</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">{{ __('Mes jetons') }}</h2>
                    <div class="mt-3 overflow-x-auto">
                        <table class="table-plain">
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
                                        <td class="font-medium">{{ $token->name }}</td>
                                        <td class="whitespace-nowrap">{{ $token->created_at?->format('d/m/Y H:i') }}</td>
                                        <td class="whitespace-nowrap">{{ $token->last_used_at?->format('d/m/Y H:i') ?? __('jamais') }}</td>
                                        <td class="text-right">
                                            <form method="POST" action="{{ route('account.tokens.destroy', $token->getKey()) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger">{{ __('Révoquer') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="py-6 text-center text-slate-500 dark:text-slate-400">{{ __('Aucun jeton.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

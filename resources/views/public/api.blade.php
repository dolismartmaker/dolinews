@extends('layouts.public')

@section('title', __('Documentation de l\'API'))

@section('content')
    {{-- Rendered from resources/openapi/v1.json, the same document
         /api/v1/openapi.json serves: the page and the specification
         cannot drift apart. Server-side on purpose, a public page here
         loads no JavaScript. --}}
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="card">
            <div class="card-body sm:p-8">
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Documentation de l\'API') }}</h1>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Version') }} {{ $version }} -
                    <a class="link" href="{{ route('api.openapi') }}">{{ __('spécification OpenAPI') }}</a>
                </p>

                <p class="mt-4 text-slate-700 dark:text-slate-200">
                    {{ __('Base des appels :') }}
                    <code class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-sm dark:bg-slate-800">{{ $baseUrl }}</code>
                </p>

                @foreach ($info['description_paragraphs'] as $paragraph)
                    <p class="mt-3 text-slate-700 dark:text-slate-200">{{ $paragraph }}</p>
                @endforeach

                <nav class="mt-6 flex flex-wrap gap-2 border-t border-slate-100 pt-5 dark:border-slate-800" aria-label="{{ __('Points d\'entrée') }}">
                    @foreach ($groups as $group)
                        <a class="chip" href="#{{ $group['anchor'] }}">{{ $group['name'] }}</a>
                    @endforeach
                    <a class="chip" href="#erreurs">{{ __('Codes d\'erreur') }}</a>
                </nav>
            </div>
        </div>

        <div class="card">
            <div class="card-body sm:p-8">
                <h2 class="text-xl font-semibold tracking-tight">{{ __('Soumettre un article, de bout en bout') }}</h2>
                {{-- A token grants the right to submit, never to publish
                     (SPEC 5.2): said here because this is where an integration
                     is written. --}}
                <p class="mt-2 text-slate-700 dark:text-slate-200">{{ __('Quatre étapes. Un jeton donne le droit de soumettre : la publication vient de la revue, jamais de l\'appel.') }}</p>

                <ol class="mt-4 list-decimal space-y-2 pl-6 text-slate-700 dark:text-slate-200">
                    <li>
                        {{ __('Ouvrez un compte contributeur et créez un jeton depuis la page des jetons de votre compte. Le secret ne s\'affiche qu\'une fois.') }}
                        <a class="link" href="{{ route('pages.editor-guide') }}">{{ __('Le parcours complet, étape par étape') }}</a>
                        @auth
                            - <a class="link" href="{{ route('account.tokens') }}">{{ __('Mes jetons d\'API') }}</a>
                        @endauth
                    </li>
                    <li>{{ __('Déposez vos captures avec POST /media et notez les identifiants renvoyés.') }}</li>
                    <li>{{ __('Créez l\'article avec POST /articles, en citant les URL des images dans le corps et leurs identifiants dans media_ids.') }}</li>
                    {{-- The path is kept outside the translated string: a brace
                         inside a Blade expression closes it early. --}}
                    <li>
                        {{ __('Soumettez-le à la revue avec') }}
                        <code class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-sm dark:bg-slate-800">POST /articles/{id}/submit</code>{{ __(', ou directement en posant submit à true à l\'étape précédente.') }}
                    </li>
                </ol>

                <pre class="code-block mt-4"><code>TOKEN="1|abc..."

# 1. Déposer une capture
curl -H "Authorization: Bearer $TOKEN" \
     -F file=@capture.png -F editor_id=3 -F alt="Écran de configuration" \
     {{ $baseUrl }}/media

# 2. Créer l'article et le soumettre dans le même appel
curl -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"editor_id":3,"type":"release","title":"Module XY 2.1",
          "version":"2.1.0","locale":"fr_FR","maturity":"stable",
          "summary":"Correction de la génération des relances.",
          "body":"## Corrections\n\n- relances","media_ids":[42],
          "submit":true}' \
     {{ $baseUrl }}/articles</code></pre>

                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">{{ __('Aucun délai de revue n\'est annoncé : l\'article est publié quand la revue l\'a validé. Le délai affiché sur le service est le délai observé, la médiane des dernières décisions.') }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-body sm:p-8">
                <h2 class="text-xl font-semibold tracking-tight">{{ __('Authentification') }}</h2>
                <p class="mt-2 text-slate-700 dark:text-slate-200">{{ $tokenNotice }}</p>
                <pre class="code-block mt-4"><code>Authorization: Bearer &lt;{{ __('votre jeton') }}&gt;</code></pre>

                <h2 class="mt-8 text-xl font-semibold tracking-tight">{{ __('Limitation de débit') }}</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="table-plain">
                        <thead>
                            <tr>
                                <th>{{ __('Appels') }}</th>
                                <th>{{ __('Sans compte') }}</th>
                                <th>{{ __('Avec un jeton') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($throttles as $name => $limits)
                                <tr>
                                    <td>{{ $name === 'write' ? __('Écriture') : __('Lecture') }}</td>
                                    <td>{{ $limits['anonymous'] ?? '' }}</td>
                                    <td>{{ $limits['authenticated'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @foreach ($groups as $group)
            <section class="card" id="{{ $group['anchor'] }}">
                <div class="card-body sm:p-8">
                    <h2 class="text-xl font-semibold tracking-tight">{{ $group['name'] }}</h2>
                    <p class="mt-2 text-slate-700 dark:text-slate-200">{{ $group['description'] }}</p>

                    <div class="mt-6 divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($group['operations'] as $operation)
                            <article class="py-6 first:pt-0 last:pb-0" id="{{ $operation['operationId'] ?? '' }}">
                                <h3 class="flex flex-wrap items-center gap-2">
                                    <span class="api-method api-method-{{ strtolower($operation['method']) }}">{{ $operation['method'] }}</span>
                                    <code class="font-mono text-sm font-medium">{{ $operation['path'] }}</code>
                                </h3>

                                <p class="mt-2 flex flex-wrap gap-1.5">
                                    @if ($operation['authenticated'])
                                        <span class="badge">{{ __('jeton requis') }}</span>
                                    @else
                                        <span class="badge">{{ __('sans compte') }}</span>
                                    @endif

                                    @if ($operation['contributor_only'])
                                        <span class="badge badge-info">{{ __('compte contributeur') }}</span>
                                    @endif

                                    @if (($operation['x-throttle'] ?? null) === 'write')
                                        <span class="badge badge-warning">{{ __('limite d\'écriture') }}</span>
                                    @endif
                                </p>

                                <p class="mt-2 font-medium">{{ $operation['summary'] ?? '' }}</p>

                                @foreach ($operation['description_paragraphs'] as $paragraph)
                                    <p class="mt-2 text-sm text-slate-700 dark:text-slate-200">{{ $paragraph }}</p>
                                @endforeach

                                @if (! empty($operation['path_fields']))
                                    <h4 class="mt-4 text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">{{ __('Paramètres de chemin') }}</h4>
                                    @include('partials.api-fields', ['fields' => $operation['path_fields']])
                                @endif

                                @if (! empty($operation['query_fields']))
                                    <h4 class="mt-4 text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">{{ __('Paramètres de requête') }}</h4>
                                    @include('partials.api-fields', ['fields' => $operation['query_fields']])
                                @endif

                                @if (! empty($operation['request_fields']))
                                    <h4 class="mt-4 text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                                        {{ __('Corps de la requête') }}
                                        <span class="font-mono text-xs normal-case">{{ $operation['request_media_type'] }}</span>
                                    </h4>
                                    @include('partials.api-fields', ['fields' => $operation['request_fields']])
                                @endif

                                @if (! empty($operation['response_rows']))
                                    <h4 class="mt-4 text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">{{ __('Réponses') }}</h4>
                                    <div class="mt-2 overflow-x-auto">
                                        <table class="table-plain">
                                            <tbody>
                                                @foreach ($operation['response_rows'] as $response)
                                                    <tr>
                                                        <td class="w-20 whitespace-nowrap"><code class="font-mono text-xs">{{ $response['status'] }}</code></td>
                                                        <td>{{ $response['description'] }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>
        @endforeach

        <div class="card" id="erreurs">
            <div class="card-body sm:p-8">
                <h2 class="text-xl font-semibold tracking-tight">{{ __('Codes d\'erreur') }}</h2>
                <p class="mt-2 text-slate-700 dark:text-slate-200">
                    {{ __('Une erreur répond sur cette forme :') }}
                    <code class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-sm dark:bg-slate-800">{"error": "CODE", "message": "...", "detail": {...}}</code>.
                    {{ __('Branchez votre intégration sur le code, jamais sur le message.') }}
                </p>

                <div class="mt-3 overflow-x-auto">
                    <table class="table-plain">
                        <thead>
                            <tr>
                                <th>{{ __('Code') }}</th>
                                <th>{{ __('Statut HTTP') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($errorCodes as $code => $status)
                                <tr>
                                    <td><code class="font-mono text-xs">{{ $code }}</code></td>
                                    <td>{{ $status }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

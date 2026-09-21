@extends('layouts.public')

@section('title', __('Documentation de l\'API'))

@section('content')
    {{-- Rendered from resources/openapi/v1.json, the same document
         /api/v1/openapi.json serves: the page and the specification
         cannot drift apart. Server-side on purpose, a public page here
         loads no JavaScript. --}}
    <div class="card">
        <h1 style="font-size:1.3rem; margin:0 0 0.5rem">{{ __('Documentation de l\'API') }}</h1>
        <p class="hint">
            {{ __('Version') }} {{ $version }} -
            <a href="{{ route('api.openapi') }}">{{ __('spécification OpenAPI') }}</a>
        </p>

        <p>{{ __('Base des appels :') }} <code>{{ $baseUrl }}</code></p>

        @foreach ($info['description_paragraphs'] as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach
    </div>

    <div class="card">
        <h2>{{ __('Soumettre un article, de bout en bout') }}</h2>
        <p>{{ __('Quatre étapes. Un jeton donne le droit de soumettre : la publication vient de la revue, jamais de l\'appel.') }}</p>

        <ol class="api-steps">
            <li>
                {{ __('Ouvrez un compte contributeur et créez un jeton depuis la page des jetons de votre compte. Le secret ne s\'affiche qu\'une fois.') }}
                <a href="{{ route('pages.editor-guide') }}">{{ __('Le parcours complet, étape par étape') }}</a>
                @auth
                    - <a href="{{ route('account.tokens') }}">{{ __('Mes jetons d\'API') }}</a>
                @endauth
            </li>
            <li>{{ __('Déposez vos captures avec POST /media et notez les identifiants renvoyés.') }}</li>
            <li>{{ __('Créez l\'article avec POST /articles, en citant les URL des images dans le corps et leurs identifiants dans media_ids.') }}</li>
            {{-- The path is kept outside the translated string: a brace
                 inside a Blade expression closes it early. --}}
            <li>
                {{ __('Soumettez-le à la revue avec') }}
                <code>POST /articles/{id}/submit</code>{{ __(', ou directement en posant submit à true à l\'étape précédente.') }}
            </li>
        </ol>

        <pre class="api-example"><code>TOKEN="1|abc..."

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

        <p class="hint">{{ __('Aucun délai de revue n\'est annoncé : l\'article est publié quand la revue l\'a validé. Le délai affiché sur le service est le délai observé, la médiane des dernières décisions.') }}</p>
    </div>

    <div class="card">
        <h2>{{ __('Authentification') }}</h2>
        <p>{{ $tokenNotice }}</p>
        <pre class="api-example"><code>Authorization: Bearer &lt;{{ __('votre jeton') }}&gt;</code></pre>

        <h2 style="margin-top:1.25rem">{{ __('Limitation de débit') }}</h2>
        <table class="plain">
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

    <div class="card">
        <h2>{{ __('Points d\'entrée') }}</h2>
        <ul class="api-toc">
            @foreach ($groups as $group)
                <li><a href="#{{ $group['anchor'] }}">{{ $group['name'] }}</a></li>
            @endforeach
        </ul>
    </div>

    @foreach ($groups as $group)
        <section class="card" id="{{ $group['anchor'] }}">
            <h2>{{ $group['name'] }}</h2>
            <p>{{ $group['description'] }}</p>

            @foreach ($group['operations'] as $operation)
                <article class="api-op" id="{{ $operation['operationId'] ?? '' }}">
                    <h3>
                        <span class="api-method {{ strtolower($operation['method']) }}">{{ $operation['method'] }}</span>
                        <code>{{ $operation['path'] }}</code>
                    </h3>

                    <p class="api-badges">
                        @if ($operation['authenticated'])
                            <span class="badge">{{ __('jeton requis') }}</span>
                        @else
                            <span class="badge">{{ __('sans compte') }}</span>
                        @endif

                        @if ($operation['contributor_only'])
                            <span class="badge">{{ __('compte contributeur') }}</span>
                        @endif

                        @if (($operation['x-throttle'] ?? null) === 'write')
                            <span class="badge">{{ __('limite d\'écriture') }}</span>
                        @endif
                    </p>

                    <p class="api-summary">{{ $operation['summary'] ?? '' }}</p>

                    @foreach ($operation['description_paragraphs'] as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach

                    @if (! empty($operation['path_fields']))
                        <h4>{{ __('Paramètres de chemin') }}</h4>
                        @include('partials.api-fields', ['fields' => $operation['path_fields']])
                    @endif

                    @if (! empty($operation['query_fields']))
                        <h4>{{ __('Paramètres de requête') }}</h4>
                        @include('partials.api-fields', ['fields' => $operation['query_fields']])
                    @endif

                    @if (! empty($operation['request_fields']))
                        <h4>{{ __('Corps de la requête') }} <span class="api-type">{{ $operation['request_media_type'] }}</span></h4>
                        @include('partials.api-fields', ['fields' => $operation['request_fields']])
                    @endif

                    @if (! empty($operation['response_rows']))
                        <h4>{{ __('Réponses') }}</h4>
                        <table class="plain">
                            <tbody>
                                @foreach ($operation['response_rows'] as $response)
                                    <tr>
                                        <td class="api-status"><code>{{ $response['status'] }}</code></td>
                                        <td>{{ $response['description'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </article>
            @endforeach
        </section>
    @endforeach

    <div class="card" id="erreurs">
        <h2>{{ __('Codes d\'erreur') }}</h2>
        <p>
            {{ __('Une erreur répond sur cette forme :') }}
            <code>{"error": "CODE", "message": "...", "detail": {...}}</code>.
            {{ __('Branchez votre intégration sur le code, jamais sur le message.') }}
        </p>
        <table class="plain">
            <thead>
                <tr>
                    <th>{{ __('Code') }}</th>
                    <th>{{ __('Statut HTTP') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($errorCodes as $code => $status)
                    <tr>
                        <td><code>{{ $code }}</code></td>
                        <td>{{ $status }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection

{{-- One field table of the API documentation: query parameters, path
     parameters or request body. Rows come flattened from OpenApiSpec,
     so this template never inspects a JSON schema. --}}
@if (! empty($fields))
    <table class="plain api-fields">
        <thead>
            <tr>
                <th>{{ __('Champ') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Requis') }}</th>
                <th>{{ __('Description') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($fields as $field)
                <tr>
                    <td><code>{{ $field['name'] }}</code></td>
                    <td class="api-type">{{ $field['type'] }}</td>
                    <td>{{ $field['required'] ? __('oui') : __('non') }}</td>
                    <td>
                        {{ $field['description'] }}
                        @if (! empty($field['enum']))
                            <span class="api-enum">{{ __('Valeurs :') }} {{ implode(', ', $field['enum']) }}</span>
                        @endif
                        @if (isset($field['default']))
                            <span class="api-enum">{{ __('Défaut :') }} {{ $field['default'] }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

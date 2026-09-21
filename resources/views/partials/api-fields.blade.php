{{-- One field table of the API documentation: query parameters, path
     parameters or request body. Rows come flattened from OpenApiSpec,
     so this template never inspects a JSON schema. --}}
@if (! empty($fields))
    <div class="mt-2 overflow-x-auto">
        <table class="table-plain">
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
                        <td class="whitespace-nowrap"><code class="font-mono text-xs">{{ $field['name'] }}</code></td>
                        <td class="font-mono text-xs whitespace-nowrap text-slate-500 dark:text-slate-400">{{ $field['type'] }}</td>
                        <td class="whitespace-nowrap">{{ $field['required'] ? __('oui') : __('non') }}</td>
                        <td>
                            {{ $field['description'] }}
                            @if (! empty($field['enum']))
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ __('Valeurs :') }} {{ implode(', ', $field['enum']) }}</span>
                            @endif
                            @if (isset($field['default']))
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ __('Défaut :') }} {{ $field['default'] }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

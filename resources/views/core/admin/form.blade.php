<div>
    <form class="admin-form" wire:submit="save">
        @foreach ($fields as $field)
            <div class="field">
                <label for="field-{{ $field['key'] }}">{{ $field['label'] }}</label>

                @if (($field['type'] ?? 'text') === 'textarea')
                    <textarea
                        id="field-{{ $field['key'] }}"
                        wire:model="form.{{ $field['key'] }}"
                        rows="4"
                    ></textarea>
                @else
                    <input
                        id="field-{{ $field['key'] }}"
                        type="{{ $field['type'] ?? 'text' }}"
                        wire:model="form.{{ $field['key'] }}"
                    >
                @endif

                @error('form.'.$field['key'])
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>
        @endforeach

        <button type="submit">Enregistrer</button>
    </form>
</div>

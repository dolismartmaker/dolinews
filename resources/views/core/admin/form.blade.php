<div>
    <h1 class="mb-5 text-2xl font-semibold tracking-tight">{{ $heading }}</h1>

    <div class="card max-w-2xl">
        <div class="card-body">
            <form wire:submit="save" class="space-y-4">
                @foreach ($fields as $field)
                    <div class="form-control">
                        <label class="label" for="field-{{ $field['key'] }}">{{ $field['label'] }}</label>

                        @if (($field['type'] ?? 'text') === 'textarea')
                            <textarea
                                id="field-{{ $field['key'] }}"
                                class="input @error('form.'.$field['key']) input-error @enderror"
                                rows="4"
                                wire:model="form.{{ $field['key'] }}"
                            ></textarea>
                        @else
                            <input
                                id="field-{{ $field['key'] }}"
                                class="input @error('form.'.$field['key']) input-error @enderror"
                                type="{{ $field['type'] ?? 'text' }}"
                                wire:model="form.{{ $field['key'] }}"
                            >
                        @endif

                        @error('form.'.$field['key'])
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach

                <button type="submit" class="btn btn-primary">{{ __('Enregistrer') }}</button>
            </form>
        </div>
    </div>
</div>

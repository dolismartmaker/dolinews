{{-- Flash status and validation errors, at the head of the main column. --}}
@if (session('status'))
    <div class="alert alert-success mb-6">{{ session('status') }}</div>
@endif

@if ($errors->any())
    <div class="alert alert-error mb-6">
        <p class="font-medium">{{ __('Des erreurs empêchent la soumission :') }}</p>
        <ul class="mt-1 list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

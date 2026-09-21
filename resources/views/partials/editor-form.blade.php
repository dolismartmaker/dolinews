{{-- Creation of the editor an account publishes for (SPEC 4.1). Shown
     on two pages: right after the contribution proof, where it is the
     next step, and on the article list, where its absence blocks any
     submission. The $intro line differs, the form does not. --}}
<div class="card">
    <div class="card-body">
        <h2 class="card-title">{{ __('Créer un éditeur') }}</h2>
        <p class="mt-2 text-slate-700 dark:text-slate-200">{{ $intro }}</p>

        <form method="POST" action="{{ route('account.editors.store') }}" class="mt-4 space-y-4">
            @csrf

            <div class="form-control">
                <label class="label" for="e-name">{{ __('Nom de l\'éditeur') }}</label>
                <input class="input" id="e-name" type="text" name="name" value="{{ old('name') }}" required maxlength="150">
                <p class="field-hint">{{ __('Ce nom est public : c\'est lui qui signe vos annonces.') }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="form-control">
                    <label class="label" for="e-contact">{{ __('Courriel de contact') }}</label>
                    <input class="input" id="e-contact" type="email" name="contact_email" value="{{ old('contact_email') }}" required>
                </div>

                <div class="form-control">
                    <label class="label" for="e-website">{{ __('Site web (facultatif)') }}</label>
                    <input class="input" id="e-website" type="url" name="website" value="{{ old('website') }}">
                </div>
            </div>

            <div class="form-control">
                <label class="label" for="e-description">{{ __('Présentation (facultative)') }}</label>
                <textarea class="input" id="e-description" name="description" rows="4" maxlength="2000">{{ old('description') }}</textarea>
            </div>

            <button type="submit" class="btn btn-primary">{{ __('Créer l\'éditeur') }}</button>
        </form>
    </div>
</div>

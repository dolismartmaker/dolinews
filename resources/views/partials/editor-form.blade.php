{{-- Creation of the editor an account publishes for (SPEC 4.1). Shown
     on two pages: right after the contribution proof, where it is the
     next step, and on the article list, where its absence blocks any
     submission. The $intro line differs, the form does not. --}}
<div class="card">
    <h2>{{ __('Créer un éditeur') }}</h2>
    <p>{{ $intro }}</p>
    <form method="POST" action="{{ route('account.editors.store') }}" class="stack">
        @csrf
        <div class="field">
            <label for="e-name">{{ __('Nom de l\'éditeur') }}</label>
            <input id="e-name" type="text" name="name" value="{{ old('name') }}" required maxlength="150">
            <p class="hint">{{ __('Ce nom est public : c\'est lui qui signe vos annonces.') }}</p>
        </div>
        <div class="field">
            <label for="e-contact">{{ __('Courriel de contact') }}</label>
            <input id="e-contact" type="email" name="contact_email" value="{{ old('contact_email') }}" required>
        </div>
        <div class="field">
            <label for="e-website">{{ __('Site web (facultatif)') }}</label>
            <input id="e-website" type="url" name="website" value="{{ old('website') }}">
        </div>
        <div class="field">
            <label for="e-description">{{ __('Présentation (facultative)') }}</label>
            <textarea id="e-description" name="description" maxlength="2000">{{ old('description') }}</textarea>
        </div>
        <button type="submit">{{ __('Créer l\'éditeur') }}</button>
    </form>
</div>

{{-- Subscribing without an account (SPEC 6.4): an address, a click in
     the mail, and it is done. The reader this serves runs Dolibarr and
     must hear about a security fix on a module deployed at their place;
     asking them for a password and a back-office first is asking them
     to leave.

     @param string $action  Route the address is posted to.
     @param string $checkboxId  Unique on the page, since a sheet may
                                carry two of these forms.
     @param ?int $fromArticle  Article to come back to, when the form is
                               shown beside one: the reader was reading
                               it, and the sheet is not where they left
                               off. --}}
@if (session('subscribed'))
    <div class="mt-3 rounded-lg border border-teal-200 bg-teal-50 p-3 text-sm text-teal-900 dark:border-teal-900 dark:bg-teal-950 dark:text-teal-100">
        {{ __('Un courriel vient de partir vers cette adresse. Ouvrez-le et confirmez : rien ne vous sera envoyé avant.') }}
    </div>
@else
    <form method="POST" action="{{ $action }}" class="mt-3 space-y-3">
        @csrf

        {{-- An article identifier and not a return address: the server
             checks it names a published article, where a URL taken from
             the form would have to be trusted. --}}
        @if (($fromArticle ?? null) !== null)
            <input type="hidden" name="from_article" value="{{ $fromArticle }}">
        @endif

        <div class="form-control">
            <label class="label" for="{{ $checkboxId }}-email">{{ __('Votre adresse de courriel') }}</label>
            <input class="input" id="{{ $checkboxId }}-email" type="email" name="email"
                value="{{ old('email') }}" required maxlength="255" autocomplete="email">
        </div>

        {{-- A bot fills every input it finds, a browser never shows this
             one. Hidden from assistive technology too, so nobody is
             asked to fill a field that disqualifies them. --}}
        <div class="hidden" aria-hidden="true">
            <label for="{{ $checkboxId }}-website">{{ __('Laissez ce champ vide') }}</label>
            <input id="{{ $checkboxId }}-website" type="text" name="website" tabindex="-1" autocomplete="off">
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" id="{{ $checkboxId }}" name="security" value="1">
            <span>{{ __('Correctifs de sécurité uniquement') }}</span>
        </label>

        <button type="submit" class="btn btn-primary w-full">{{ __('Me tenir informé') }}</button>
    </form>

    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
        {{ __('Pas de compte à créer, pas de mot de passe. Chaque courriel porte un lien pour tout arrêter.') }}
    </p>
@endif

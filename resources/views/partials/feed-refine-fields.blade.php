{{-- Fields of the feed filters, shared by every skin's filter box: the
     search and the maturities already set ride along hidden, then the four
     structured filters (SPEC 6.1). The box around them is the skin's.

     No language filter: the feed follows the interface language, chosen
     once in the header. --}}
{{-- The search typed in the header stays in force: these
     fields narrow it, they do not replace it. --}}
@if (($filters['search'] ?? null) !== null)
    <input type="hidden" name="q" value="{{ $filters['search'] }}">
@endif
@foreach ($filters['maturities'] ?? [] as $maturity)
    <input type="hidden" name="maturity[]" value="{{ $maturity }}">
@endforeach

<div class="refine-field">
    <label for="f-editor">{{ __('Éditeur') }}</label>
    <input class="input" type="search" id="f-editor" name="editor" value="{{ $filters['editor'] ?? '' }}" placeholder="cap-rel">
</div>

<div class="refine-field">
    <label for="f-project">{{ __('Projet') }}</label>
    <input class="input" type="search" id="f-project" name="project" value="{{ $filters['project'] ?? '' }}" placeholder="dolinews">
</div>

<div class="refine-field">
    <label for="f-dolibarr">{{ __('Concerne Dolibarr') }}</label>
    {{-- The version filter targets announcements, never
         modules (D1): the service never states the
         current state of a module, only what was
         announced. --}}
    <select class="input" id="f-dolibarr" name="dolibarr">
        <option value="">{{ __('toutes versions') }}</option>
        @foreach ($dolibarrMajors as $major)
            <option value="{{ $major }}" @selected((int) ($filters['dolibarr'] ?? 0) === $major)>v{{ $major }}</option>
        @endforeach
    </select>
</div>

<div class="refine-field">
    <label for="f-focus">{{ __('Focus') }}</label>
    <select class="input" id="f-focus" name="focus">
        <option value="">{{ __('tous') }}</option>
        @foreach ($focusList as $focus)
            <option value="{{ $focus->value }}" @selected(($filters['focus'] ?? null) === $focus->value)>{{ $focus->label() }}</option>
        @endforeach
    </select>
</div>

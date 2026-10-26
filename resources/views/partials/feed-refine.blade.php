{{-- Structured filters of the feed, in its side column (SPEC 6.1). --}}
@php($filtering = collect(['editor', 'project', 'dolibarr', 'focus'])->contains(fn (string $field): bool => request()->filled($field)))

{{-- Folded under a toggle below the large breakpoint, and the
     toggle is a checkbox rather than a details element: the
     public pages carry no JavaScript, and a details element
     cannot be held open by the stylesheet once the column has
     room for it. Unfolded when a filter is already set, so that
     the reader sees what narrows the feed. --}}
<div class="card">
    <input type="checkbox" id="refine-toggle" class="peer sr-only" @checked($filtering)>
    <label for="refine-toggle" class="flex cursor-pointer items-center justify-between px-5 py-3 text-sm font-semibold peer-focus-visible:outline-2 peer-focus-visible:outline-accent-600 lg:hidden">
        {{ __('Affiner') }}
        <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
        </svg>
    </label>

    <form method="GET" action="{{ route('home') }}" class="hidden px-5 pb-5 peer-checked:block lg:block lg:pt-5">
        <p class="mb-3 hidden text-sm font-semibold lg:block">{{ __('Affiner') }}</p>

        {{-- The search typed in the header stays in force: these
             fields narrow it, they do not replace it. --}}
        @if (($filters['search'] ?? null) !== null)
            <input type="hidden" name="q" value="{{ $filters['search'] }}">
        @endif
        @foreach ($filters['maturities'] ?? [] as $maturity)
            <input type="hidden" name="maturity[]" value="{{ $maturity }}">
        @endforeach

        <div class="space-y-3">
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

            {{-- No language filter here: the feed follows the
                 interface language, chosen once in the header. --}}
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <button type="submit" class="btn btn-sm btn-primary">{{ __('Filtrer') }}</button>
            @if (request()->query())
                <a class="btn btn-sm btn-ghost" href="{{ route('home') }}">{{ __('Tout afficher') }}</a>
            @endif
        </div>
    </form>
</div>

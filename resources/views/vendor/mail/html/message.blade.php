<x-mail::layout>
{{-- Inbox preview line, when the mail sets one --}}
@isset($preheader)
<x-slot:preheader>{{ $preheader }}</x-slot:preheader>
@endisset

{{-- Header: the name of the service, leading to the feed in the
     language of the recipient --}}
<x-slot:header>
<x-mail::header :url="route('home')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer: what the service is, and under which licence what it
     carries is published. The framework footer said "All rights
     reserved", in English whatever the reader's language, on contents
     placed under CC BY-SA 4.0 (SPEC D15). --}}
<x-slot:footer>
<x-mail::footer>
{{ __('Annonces de l\'écosystème Dolibarr : ce qui a été annoncé, et quand.') }}

{{ __('Contenus publiés sous licence CC BY-SA 4.0, propriété de leurs auteurs. Code du service sous GNU AGPL v3.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>

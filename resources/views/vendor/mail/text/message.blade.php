<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="route('home')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{{ $slot }}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer: same lines as the HTML version. Not indented: the text
     version prints every leading space it is given. --}}
<x-slot:footer>
<x-mail::footer>
{{ __('Annonces de l\'écosystème Dolibarr : ce qui a été annoncé, et quand.') }}
{{ __('Contenus publiés sous licence CC BY-SA 4.0, propriété de leurs auteurs. Code du service sous GNU AGPL v3.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>

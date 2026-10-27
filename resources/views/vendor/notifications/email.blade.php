<x-mail::message>
{{-- Greeting. The framework wrote "Hello!", "Regards," and its button
     hint in English, whatever the language of the recipient: the
     source strings of the service are French, and French has no
     language file to translate the framework keys into. --}}
@if (! empty($greeting))
{{ $greeting }}
@else
{{ __('Bonjour,') }}
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
{{ __('Cordialement,') }}<br>
{{ config('app.name') }}
@endif

{{-- Subcopy: the address written out, for a client that renders no
     button --}}
@isset($actionText)
<x-slot:subcopy>
{{ __('Si le bouton ":action" ne fonctionne pas, copiez cette adresse dans votre navigateur :', ['action' => $actionText]) }} <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>

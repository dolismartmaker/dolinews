<x-mail::message>
# {{ __('Vos préférences d\'abonnement') }}

{{ __('Ouvrez cette page pour choisir ce que vous recevez, à quelle fréquence, et ce que vous suivez.') }}

<x-mail::button :url="$url">
{{ __('Ouvrir mes préférences') }}
</x-mail::button>

{{ __('Ce lien est valable :minutes minutes.', ['minutes' => $minutes]) }}

<x-slot:subcopy>
{{ __('Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : rien n\'a changé dans votre abonnement.') }}
</x-slot:subcopy>
</x-mail::message>

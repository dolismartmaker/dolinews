<x-mail::message>
# {{ __('Confirmez votre abonnement') }}

@if ($targetName)
{{ __('Vous avez demandé à être informé des annonces de :') }} **{{ $targetName }}**
@else
{{ __('Vous avez demandé à être informé des annonces publiées sur DoliNews.') }}
@endif

@if ($securityOnly)
{{ __('Vous ne recevrez que les correctifs de sécurité.') }}
@endif

<x-mail::button :url="$url">
{{ __('Confirmer mon abonnement') }}
</x-mail::button>

{{-- The click is the whole protection: the form takes an address typed
     by whoever passes by, so without it anyone could subscribe somebody
     else, and the domain would lose its deliverability to the
     complaints that follow. --}}
{{ __('Tant que vous n\'avez pas confirmé, aucun courriel d\'annonce ne part vers cette adresse.') }}

{{ __('Ce lien est valable :hours heures.', ['hours' => $hours]) }}

<x-slot:subcopy>
{{ __('Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : sans confirmation de votre part, rien ne sera envoyé et la demande sera effacée.') }}
</x-slot:subcopy>
</x-mail::message>

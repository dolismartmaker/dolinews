<x-mail::message>
# {{ __('Le volume de traduction du mois est atteint') }}

{{-- A state, never a breakage (SPEC 5.7): said before anything else,
     because that is the question the subject line raises. --}}
{{ __('Les annonces de :editeur restent publiées, diffusées et filtrées comme avant : une annonce non traduite n\'est jamais pénalisée. Seules les nouvelles versions traduites par le service s\'arrêtent, jusqu\'au :date.', ['editeur' => $editor->name, 'date' => $renewsOn->locale(app()->getLocale())->isoFormat('LL')]) }}

{{ __('Volume utilisé ce mois :') }} **{{ number_format($characters, 0, ',', ' ') }} / {{ number_format($ceiling, 0, ',', ' ') }}** {{ __('caractères') }}

## {{ __('Deux façons de continuer sans attendre') }}

{{-- DeepL is named: it is a supplier the editor deals with itself, and
     it has to be told where the key goes. The engine the service
     translates on is named nowhere. --}}
**1. {{ __('Renseigner votre propre clé DeepL') }}**

{{ __('Votre clé traduit sans aucun volume de notre côté, et vous ne nous devez rien pour cela. Elle est chiffrée au repos et jamais réaffichée.') }}

<x-mail::button :url="$editorUrl">
{{ __('Ouvrir la traduction automatique') }}
</x-mail::button>

**2. {{ __('Déposer vous-même vos versions traduites') }}**

{{ __('L\'API accepte une version traduite comme n\'importe quelle annonce, avec le groupe de traduction et la langue : elle paraît à la date de sa source, sans volume ni attente. Vous pouvez aussi mandater un compte contributeur pour le faire pour vous.') }}

{{-- Both buttons in the default colour: "secondary" is not one the
     mail theme knows, and an unknown colour renders the button
     invisible. The two ways out are worth the same anyway, which is
     the point of naming them together. --}}
<x-mail::button :url="$apiUrl">
{{ __('Lire la documentation de l\'API') }}
</x-mail::button>

<x-slot:subcopy>
{{-- No unsubscribe link: this is transactional, like the review
     circuit mails. Leaving it would mean not being told that the
     translation of one's own announcements has stopped. It is sent once
     per month at most, and only to an editor that asked for automatic
     translation. --}}
{{ __('Vous recevez ce message parce que la traduction automatique est activée pour votre éditeur. Il part une fois par mois au plus.') }}
</x-slot:subcopy>
</x-mail::message>

<x-mail::message>
# Vérification de contribution DoliNews

Ce code à usage unique confirme que l'adresse de commit saisie vous appartient. Il expire dans une heure.

<x-mail::panel>
{{ $code }}
</x-mail::panel>

Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : rien ne se passera.

<x-mail::button :url="route('account.contribute')">
Retour à mon compte
</x-mail::button>

Merci, l'équipe DoliNews.
</x-mail::message>

<x-mail::message>
# {{ __('Nouvelles annonces') }}

@foreach ($articles as $article)
## {{ $article->title }}

**{{ $article->editor->name }}**@if ($article->project) - {{ $article->project->name }}@endif @if ($article->version) - {{ __('version') }} {{ $article->version }}@endif
@if ($article->focus)
{{ __('Focus') }} : {{ $article->focus->label() }}
@endif

{{ $article->summary }}

<x-mail::button :url="route('articles.show', $article)">
{{ __('Lire l\'annonce') }}
</x-mail::button>

@if (! $loop->last)
---
@endif
@endforeach

<x-slot:subcopy>
{{-- The unsubscribe footer, on every subscription mail without
     exception: a reader who cannot leave from the mail itself reports
     it as spam instead, and that costs the whole domain its
     deliverability. --}}
{{ __('Vous recevez ce message parce que vous êtes abonné aux annonces de DoliNews.') }}

[{{ __('Ne plus recevoir ces courriels') }}]({{ $unsubscribeUrl }}) - [{{ __('Choisir ce que je reçois') }}]({{ $accountUrl }})
</x-slot:subcopy>
</x-mail::message>

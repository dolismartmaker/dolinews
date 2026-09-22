<x-mail::message>
# {{ __('Nouvelles annonces') }}

@foreach ($articles as $article)
## {{ $article->title }}

{{-- One meta line and not two: a mail client collapses the blank line
     between them anyway, and the editor, the version and the focus read
     as one answer to "does this concern me". --}}
**{{ $article->editor->name }}**@if ($article->project) - {{ $article->project->name }}@endif @if ($article->version) - {{ __('version') }} {{ $article->version }}@endif @if ($article->focus) - {{ $article->focus->label() }}@endif

{{ $article->summary }}

<x-mail::button :url="\App\Domain\Dolinews\Seo\ArticleUrl::for($article)">
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

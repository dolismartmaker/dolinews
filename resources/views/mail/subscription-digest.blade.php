<x-mail::message>
<x-slot:preheader>{{ $preheader }}</x-slot:preheader>

# {{ $heading }}

@if ($intro !== null)
<p class="digest-intro">{{ $intro }}</p>
@endif

{{-- Every entry, in the order of the feed (published_at descending, as
     everywhere): a security announcement is marked, never moved. --}}
@foreach ($articles as $article)
<x-mail::announcement :article="$article" :last="$loop->last" />

@endforeach

<x-slot:subcopy>
{{-- The unsubscribe footer, on every subscription mail without
     exception: a reader who cannot leave from the mail itself reports
     it as spam instead, and that costs the whole domain its
     deliverability. --}}
{{ __('Vous recevez ce message parce que vous êtes abonné aux annonces de DoliNews.') }}

[{{ __('Ne plus recevoir ces courriels') }}]({{ $unsubscribeUrl }}) - [{{ __('Choisir ce que je reçois') }}]({{ $preferencesUrl }})
</x-slot:subcopy>
</x-mail::message>

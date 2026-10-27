{{-- Plain-text version of one announcement: the same fields as the HTML
     card, one per line, and the address written out. --}}
@props(['article', 'last' => false])
@php
    $fields = array_filter([
        $article->project?->name,
        $article->editor->name,
        $article->published_at?->locale(app()->getLocale())->isoFormat('LL'),
    ]);
    $badges = array_filter([
        $article->focus?->label(),
        $article->version ? __('Version').' '.$article->version : null,
        $article->announcedDolibarrLabel(),
        $article->maturity !== \App\Domain\Dolinews\Enums\Maturity::STABLE ? $article->maturity->label() : null,
        $article->foreignLanguageLabel(app()->getLocale()),
        $article->publication_mode?->value === 'bootstrap' ? __('publié pendant l\'amorçage du service') : null,
    ]);
@endphp
{{ \Illuminate\Support\Str::squish($article->title) }}
{{ implode(' - ', $fields) }}
@if ($badges !== [])
{{ implode(' - ', $badges) }}
@endif

{{ \Illuminate\Support\Str::squish($article->summary) }}

{{ __('Lire l\'annonce') }} : {{ \App\Domain\Dolinews\Seo\ArticleUrl::for($article) }}

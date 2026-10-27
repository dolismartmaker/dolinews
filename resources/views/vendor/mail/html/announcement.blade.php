{{-- One announcement of a subscription mail (SPEC 6.4): the card of the
     feed, cut down to what a mail client renders.

     Raw HTML inside a Markdown mail: the block must hold no blank line and
     no indented line, or the Markdown parser ends it there and prints the
     rest as text. Hence no comment and no indentation below, and the title
     and summary squished to one line each.

     The date is absolute, and the maturity sits next to it rather than
     carrying a relative age (SPEC 6.3): a weekly mail is read days after it
     was written, and "announced today" would be false by then. The
     maturity shows only when it is not stable, the default every
     subscription filters on (SPEC 6.2).

     Props: $article, $last (no rule under the last entry). --}}
@props(['article', 'last' => false])
@php
    $url = \App\Domain\Dolinews\Seo\ArticleUrl::for($article);
    $logo = $article->sourceLogo();
    $featured = $article->featuredImage();
    $security = $article->focus === \App\Domain\Dolinews\Enums\Focus::SECURITY;
    $maturity = $article->maturity;
    $foreign = $article->foreignLanguageLabel(app()->getLocale());
    $range = $article->announcedDolibarrLabel();
    $classes = 'announcement'.($security ? ' announcement-security' : '').($last ? ' announcement-last' : '');
    // 600 px of body, 32 px of padding each side, 20 more beside the
    // security rule: the attribute is what Outlook reads, it ignores CSS.
    $imageWidth = $featured !== null ? min((int) $featured->width, $security ? 516 : 536) : 0;
@endphp
<table class="{{ $classes }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="announcement-cell">
<table class="announcement-head" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
@if ($logo !== null)
<td class="announcement-logo" width="32" valign="middle"><img src="{{ $logo->absoluteUrl() }}" alt="" width="32" height="32"></td>
@endif
<td class="announcement-source" valign="middle">
@if ($article->project)
<strong>{{ $article->project->name }}</strong> - {{ $article->editor->name }}
@else
<strong>{{ $article->editor->name }}</strong>
@endif
</td>
<td class="announcement-date" valign="middle" align="right">{{ $article->published_at?->locale(app()->getLocale())->isoFormat('LL') }}</td>
</tr>
</table>
<h2 class="announcement-title"><a href="{{ $url }}">{{ \Illuminate\Support\Str::squish($article->title) }}</a></h2>
<p class="announcement-badges">
@if ($article->focus)
<span class="badge{{ $security ? ' badge-danger' : '' }}">{{ $article->focus->label() }}</span>
@endif
@if ($article->version)
<span class="badge">{{ __('Version') }} {{ $article->version }}</span>
@endif
@if ($range !== null)
<span class="badge">{{ $range }}</span>
@endif
@if ($maturity !== \App\Domain\Dolinews\Enums\Maturity::STABLE)
<span class="badge{{ $maturity === \App\Domain\Dolinews\Enums\Maturity::DEPRECATED ? '' : ' badge-warning' }}">{{ $maturity->label() }}</span>
@endif
@if ($foreign !== null)
<span class="badge">{{ $foreign }}</span>
@endif
@if ($article->publication_mode?->value === 'bootstrap')
<span class="badge badge-info">{{ __('publié pendant l\'amorçage du service') }}</span>
@endif
</p>
@if ($featured !== null)
<a href="{{ $url }}"><img class="announcement-image" src="{{ $featured->absoluteUrl() }}" alt="{{ $featured->alt ?? '' }}" width="{{ $imageWidth }}"></a>
@endif
<p class="announcement-summary">{{ \Illuminate\Support\Str::squish($article->summary) }}</p>
<p class="announcement-link"><a href="{{ $url }}">{{ __('Lire l\'annonce') }}</a></p>
</td>
</tr>
</table>

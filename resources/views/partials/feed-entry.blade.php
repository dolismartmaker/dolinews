{{-- One entry of the feed: the newest announcement of a group, with the
     older ones of a burst folded under it (SPEC 6.1).

     Parameter: $group (FeedGroup). --}}
@php($article = $group->lead)
@php($sourceLogo = $article->sourceLogo())
<article class="card overflow-hidden">
    <div class="card-body">
        {{-- The date leaves the enumeration below for a flag of its
             own, flush with the right edge of the card: a dated feed
             is scanned by its dates, and buried in a row of labelled
             fields they carry no more weight than the version. --}}
        <div class="flex items-start justify-between gap-4">
            {{-- The logo names the source at a glance, as a
                 news front page shows the icon of the paper.
                 Decorative: the project and the editor are
                 named in words right beside it. --}}
            <div class="flex min-w-0 items-start gap-3">
                @if ($sourceLogo !== null)
                    <img src="{{ $sourceLogo->url() }}" alt="" loading="lazy"
                         width="40" height="40"
                         class="mt-0.5 h-10 w-10 shrink-0 rounded-lg border border-slate-200 bg-white object-contain dark:border-slate-700">
                @endif
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold tracking-tight">
                        <a class="hover:text-accent-700 dark:hover:text-accent-300" href="{{ \App\Domain\Dolinews\Seo\ArticleUrl::for($article) }}">
                            {{ $article->title }}
                        </a>
                    </h2>

                    {{-- Labelled fields rather than a bare enumeration: the
                         editor signs the announcement and answers for it, so
                         it is named even when a project sheet is attached.
                         Reading the project alone never says who published. --}}
                    {{-- Separator drawn by the stylesheet on every span but
                         the first: written in the markup it had to follow
                         each optional field, and the last one present left
                         a bar hanging at the end of the line. --}}
                    <p class="meta-line mt-1 flex flex-col items-start gap-x-2 gap-y-1 text-sm text-slate-500 sm:flex-row sm:flex-wrap sm:items-center dark:text-slate-400">
                        @if ($article->project)
                            <span>{{ __('Projet') }} :
                                <a class="link" href="{{ route('projects.show', $article->project->slug) }}">{{ $article->project->name }}</a>
                            </span>
                        @endif

                        @if ($article->editor)
                            <span>{{ __('Éditeur') }} :
                                <a class="link" href="{{ route('editors.show', $article->editor->slug) }}">{{ $article->editor->name }}</a>
                            </span>
                        @endif

                        @if ($article->version)
                            <span>{{ __('Version') }} : {{ $article->version }}</span>
                        @endif

                        @include('partials.dolibarr-range', ['article' => $article])
                    </p>
                </div>
            </div>

            <div class="-mt-5 -mr-5 sm:-mt-6 sm:-mr-6">
                @include('partials.date-flag', ['date' => $article->published_at])
            </div>
        </div>

        <div class="mt-3 flex flex-wrap gap-1.5">
            @if ($article->focus)
                <span class="badge {{ $article->focus->value === 'security' ? 'badge-danger' : '' }}">{{ $article->focus->label() }}</span>
            @endif

            {{-- Maturity always WITH its age (SPEC 6.3): nobody ever
                 comes back to say a version left its test phase, so
                 the reader judges the pair alone. --}}
            <span class="badge {{ in_array($article->maturity->value, ['alpha', 'beta', 'rc'], true) ? 'badge-warning' : ($article->maturity->value === 'deprecated' ? 'badge-neutral' : '') }}">
                {{ $article->maturity->label() }}
            </span>
            @if ($article->announcedAge() !== null)
                <span class="badge">{{ $article->announcedAge() }}</span>
            @endif

            @if ($article->publication_mode?->value === 'bootstrap')
                <span class="badge badge-info">{{ __('publié pendant l\'amorçage du service') }}</span>
            @endif

            {{-- No back-dating badge: an announcement carries
                 the date of the version it announces, and the
                 day it reached the service interests nobody
                 but the operator (SPEC 5.1). isBackdated()
                 still governs the figures the back-dating
                 would distort. --}}

            {{-- A language badge only when the announcement is
                 NOT in the language of the interface: it is
                 shown here for want of a translation, and the
                 reader has to know before clicking. Silence
                 would be a promise the card cannot keep. --}}
            @if ($article->foreignLanguageLabel(app()->getLocale()) !== null)
                <span class="badge">{{ $article->foreignLanguageLabel(app()->getLocale()) }}</span>
            @endif
        </div>

        {{-- The image the announcement puts forward, beside
             the summary it illustrates and never above the
             title: the feed is scanned by its titles and its
             dates. Its alternative text is the media's own,
             written by the author for this image. --}}
        @php($featured = $article->featuredImage())
        <div class="mt-3 flex items-start gap-4">
            <p class="min-w-0 flex-1 text-slate-700 dark:text-slate-200">{{ $article->summary }}</p>
            @if ($featured !== null)
                <a href="{{ \App\Domain\Dolinews\Seo\ArticleUrl::for($article) }}" class="shrink-0" tabindex="-1">
                    <img src="{{ $featured->url() }}" alt="{{ $featured->alt ?? '' }}" loading="lazy"
                         width="{{ $featured->width }}" height="{{ $featured->height }}"
                         class="feed-card-image">
                </a>
            @endif
        </div>
    </div>

    {{-- No comment count next to the link: the service carries no
         public comments, the discussion belongs to the Dolibarr
         forum (SPEC D10). --}}
    <div class="card-footer">
        <a class="link font-medium" href="{{ \App\Domain\Dolinews\Seo\ArticleUrl::for($article) }}">
            + {{ __('Lire la suite') }}
        </a>
    </div>

    @if ($group->isCollapsed())
        {{--
            A burst by one project folds under its newest
            announcement (SPEC 6.1), so that four releases in an
            afternoon do not push every other editor off the page.

            It folds, it never hides: the count and the range are
            stated, and each one is a click away in plain HTML -
            the service says what was announced, and an
            announcement nobody can reach was not announced. A
            security announcement never folds, and the RSS, JSON
            and email channels serve every entry.
        --}}
        <details class="border-t border-slate-200 px-5 py-3 text-sm sm:px-6 dark:border-slate-700">
            <summary class="cursor-pointer text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">
                {{-- The count alone, with every date in the list
                     below: one wording for one and for many keeps
                     the plural forms of the ten languages usable. --}}
                {{ trans_choice(
                    ':count version plus ancienne de ce projet|:count versions plus anciennes de ce projet',
                    $group->foldedCount(),
                    ['count' => $group->foldedCount()],
                ) }}
            </summary>

            <ul class="mt-3 space-y-2">
                @foreach ($group->others as $folded)
                    <li class="flex flex-wrap items-baseline gap-x-2">
                        <a class="link font-medium" href="{{ \App\Domain\Dolinews\Seo\ArticleUrl::for($folded) }}">
                            {{ $folded->title }}
                        </a>
                        @if ($folded->version)
                            <span class="text-slate-500 dark:text-slate-400">{{ $folded->version }}</span>
                        @endif
                        <span class="text-slate-400 dark:text-slate-500">
                            {{ $folded->published_at?->locale(app()->getLocale())->isoFormat('LL') }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</article>

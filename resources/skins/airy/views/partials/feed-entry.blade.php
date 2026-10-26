{{-- One entry of the feed in the airy skin: no card, the space around it is
     the frame. The date in small capitals above a large title, the labelled
     fields in soft text, the summary at reading size, the image put forward
     under it, then the tags and the link on.

     Everything the default entry states is stated here: the editor even when
     a sheet is attached, the version, the Dolibarr range, the focus, the
     maturity WITH its age (SPEC 6.3), the bootstrap mention, the language of
     an untranslated announcement, and the burst folded under its newest
     entry (SPEC 6.1).

     Parameter: $group (FeedGroup). --}}
@php($article = $group->lead)
@php($sourceLogo = $article->sourceLogo())
@php($featured = $article->featuredImage())
@php($url = \App\Domain\Dolinews\Seo\ArticleUrl::for($article))
<article class="feed-airy">
    <div class="flex items-center gap-3">
        {{-- Decorative: the project and the editor are named in words
             below the title. --}}
        @if ($sourceLogo !== null)
            <img src="{{ $sourceLogo->url() }}" alt="" loading="lazy" width="24" height="24"
                 class="h-6 w-6 shrink-0 bg-white object-contain">
        @endif
        @include('partials.date-flag', ['date' => $article->published_at])
    </div>

    <h2 class="mt-3 text-2xl leading-snug font-semibold tracking-tight text-slate-900 dark:text-white">
        <a class="hover:text-accent-700 dark:hover:text-accent-300" href="{{ $url }}">{{ $article->title }}</a>
    </h2>

    <p class="meta-line mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-[15px] text-slate-500 dark:text-slate-400">
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

    <p class="mt-5 max-w-3xl text-[17px] leading-8 text-slate-700 dark:text-slate-200">{{ $article->summary }}</p>

    {{-- Its alternative text is the media's own, written by the author for
         this image. --}}
    @if ($featured !== null)
        <a href="{{ $url }}" class="block" tabindex="-1">
            <img src="{{ $featured->url() }}" alt="{{ $featured->alt ?? '' }}" loading="lazy"
                 width="{{ $featured->width }}" height="{{ $featured->height }}"
                 class="feed-airy-image">
        </a>
    @endif

    <div class="mt-5 flex flex-wrap items-baseline gap-x-5 gap-y-2">
        @if ($article->focus)
            <span class="badge {{ $article->focus->value === 'security' ? 'badge-danger' : '' }}">{{ $article->focus->label() }}</span>
        @endif
        <span class="badge {{ in_array($article->maturity->value, ['alpha', 'beta', 'rc'], true) ? 'badge-warning' : ($article->maturity->value === 'deprecated' ? 'badge-neutral' : '') }}">
            {{ $article->maturity->label() }}
        </span>
        @if ($article->announcedAge() !== null)
            <span class="badge">{{ $article->announcedAge() }}</span>
        @endif
        @if ($article->publication_mode?->value === 'bootstrap')
            <span class="badge badge-info">{{ __('publié pendant l\'amorçage du service') }}</span>
        @endif
        @if ($article->foreignLanguageLabel(app()->getLocale()) !== null)
            <span class="badge">{{ $article->foreignLanguageLabel(app()->getLocale()) }}</span>
        @endif

        <a class="link ml-auto text-[15px] font-medium" href="{{ $url }}">{{ __('Lire la suite') }} &rarr;</a>
    </div>

    @if ($group->isCollapsed())
        {{-- Folds, never hides (SPEC 6.1): the count is stated and each
             folded announcement is a link in plain HTML. --}}
        <details class="mt-6 text-[15px]">
            <summary class="cursor-pointer text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">
                {{ trans_choice(
                    ':count version plus ancienne de ce projet|:count versions plus anciennes de ce projet',
                    $group->foldedCount(),
                    ['count' => $group->foldedCount()],
                ) }}
            </summary>

            <ul class="mt-4 space-y-3 pl-4">
                @foreach ($group->others as $folded)
                    <li class="flex flex-wrap items-baseline gap-x-3">
                        <a class="link" href="{{ \App\Domain\Dolinews\Seo\ArticleUrl::for($folded) }}">{{ $folded->title }}</a>
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

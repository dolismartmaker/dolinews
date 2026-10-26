{{-- One entry of the feed in the compact skin: a line, not a card. The date
     in a column of its own on the left, then the title, the tags, the
     labelled fields and the summary, in small type.

     Everything the default entry states is stated here, only smaller: the
     editor even when a sheet is attached, the version, the Dolibarr range,
     the focus, the maturity WITH its age (SPEC 6.3), the bootstrap mention,
     the language of an untranslated announcement, and the burst folded
     under its newest entry (SPEC 6.1). The image put forward is left out:
     it is the one wide block of the default card.

     Parameter: $group (FeedGroup). --}}
@php($article = $group->lead)
@php($sourceLogo = $article->sourceLogo())
@php($url = \App\Domain\Dolinews\Seo\ArticleUrl::for($article))
<article class="feed-row flex gap-3">
    @if ($article->published_at !== null)
        <time class="feed-row-date" datetime="{{ $article->published_at->toDateString() }}">
            {{ $article->published_at->locale(app()->getLocale())->isoFormat('D MMM YYYY') }}
        </time>
    @endif

    <div class="min-w-0 flex-1">
        <h2 class="feed-row-title">
            {{-- Decorative: the project and the editor are named in words
                 on the line below. --}}
            @if ($sourceLogo !== null)
                <img src="{{ $sourceLogo->url() }}" alt="" loading="lazy" width="16" height="16"
                     class="mr-1 inline-block h-4 w-4 bg-white object-contain align-[-2px]">
            @endif
            <a class="hover:text-accent-700 hover:underline dark:hover:text-accent-300" href="{{ $url }}">{{ $article->title }}</a>

            <span class="ml-1 inline-flex flex-wrap items-baseline gap-x-1.5 align-baseline">
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
            </span>
        </h2>

        <p class="feed-row-meta meta-line flex flex-wrap items-center gap-x-2">
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

        <p class="mt-1 text-slate-700 dark:text-slate-300">{{ $article->summary }}</p>

        @if ($group->isCollapsed())
            {{-- Folds, never hides (SPEC 6.1): the count is stated and each
                 folded announcement is a link in plain HTML. --}}
            <details class="mt-1 text-xs">
                <summary class="cursor-pointer text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">
                    {{ trans_choice(
                        ':count version plus ancienne de ce projet|:count versions plus anciennes de ce projet',
                        $group->foldedCount(),
                        ['count' => $group->foldedCount()],
                    ) }}
                </summary>

                <ul class="mt-1 space-y-0.5 pl-3">
                    @foreach ($group->others as $folded)
                        <li class="flex flex-wrap items-baseline gap-x-2">
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
    </div>
</article>

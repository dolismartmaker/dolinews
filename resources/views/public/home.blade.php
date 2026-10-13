@extends('layouts.public')

{{-- The page title says what the feed holds rather than what the site
     calls it: "Le fil" names a section to whoever is already here, and
     nothing to whoever reads it in a list of search results. --}}
@section('title', __('Annonces de l\'écosystème Dolibarr'))

@push('head')
    @include('partials.json-ld', ['data' => $structuredData])
@endpush

@section('page_width', 'max-w-[120rem]')

@section('content')
    {{-- The feed takes the width of the screen, and what is not the feed
         stands aside in a column of its own: the presentation of the service
         and the structured filters, the free-text search having moved up to
         the header. Below the large breakpoint the column comes first, so
         that the two delivery channels are still named at the head of the
         feed (SPEC 6.4) and not under twenty-five cards. --}}
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <aside class="home-aside space-y-4 lg:col-start-2 lg:row-start-1">
            {{-- Flat colours and a border, no blur and no animation: a
                 decorated hero is repainted on every scroll step and the page
                 crawls, Firefox first (LARAVEL_PAGES_PUBLIQUES 3). --}}
            <section class="card">
                <div class="card-body">
                    {{-- The review figures live on the transparency page, not
                         here: a reader who comes for the feed has no use for
                         the state of the queue, which concerns authors
                         (SPEC 5.1). --}}
                    <h1 class="text-xl font-semibold tracking-tight">
                        {{ __('Annonces de l\'écosystème Dolibarr') }}
                    </h1>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                        {{ __('Suivez toute l\'actualité à propos des modules et services proposés par les éditeurs de modules de Dolibarr.') }}
                    </p>
                    {{-- The two delivery channels in words and not in buttons
                         alone: a button named "Recevoir par courriel" says what
                         it does, never that one subscribes to a single project
                         or editor rather than to the whole feed (SPEC 6.4).
                         Naming the cadences answers the objection that comes
                         next - whoever fears a mail a day takes the weekly
                         summary instead of closing the page. --}}
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                        {{ __('Abonnez-vous à un projet ou à un éditeur pour recevoir un courriel dès qu\'une nouvelle version est annoncée, correctif de sécurité compris : à chaque publication, ou en un résumé par jour ou par semaine.') }}
                    </p>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                        {{ __('Si vous utilisez un agrégateur de flux, le flux RSS porte les mêmes annonces.') }}
                    </p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <a class="btn btn-sm btn-primary" href="{{ route('pages.editor-guide') }}">{{ __('Publier une annonce') }}</a>
                        {{-- Ahead of the feeds, and not below them: whoever
                             runs a feed reader finds the RSS button on their
                             own, whoever does not would never have learnt
                             that the announcements can reach them at all. --}}
                        <a class="btn btn-sm btn-outline" href="{{ auth()->check() ? route('account.show') : route('register') }}">{{ __('Recevoir par courriel') }}</a>
                        <a class="btn btn-sm btn-outline" href="{{ route('feeds.rss', request()->query() + ['locale' => app()->getLocale()]) }}">{{ __('Flux RSS') }}</a>
                        <a class="btn btn-sm btn-outline" href="{{ route('feeds.json', request()->query() + ['locale' => app()->getLocale()]) }}">{{ __('Flux JSON') }}</a>
                    </div>
                </div>
            </section>

            @php($filtering = collect(['editor', 'project', 'dolibarr', 'focus'])->contains(fn (string $field): bool => request()->filled($field)))

            {{-- Folded under a toggle below the large breakpoint, and the
                 toggle is a checkbox rather than a details element: the
                 public pages carry no JavaScript, and a details element
                 cannot be held open by the stylesheet once the column has
                 room for it. Unfolded when a filter is already set, so that
                 the reader sees what narrows the feed. --}}
            <div class="card">
                <input type="checkbox" id="refine-toggle" class="peer sr-only" @checked($filtering)>
                <label for="refine-toggle" class="flex cursor-pointer items-center justify-between px-5 py-3 text-sm font-semibold peer-focus-visible:outline-2 peer-focus-visible:outline-accent-600 lg:hidden">
                    {{ __('Affiner') }}
                    <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </label>

                <form method="GET" action="{{ route('home') }}" class="hidden px-5 pb-5 peer-checked:block lg:block lg:pt-5">
                    <p class="mb-3 hidden text-sm font-semibold lg:block">{{ __('Affiner') }}</p>

                    {{-- The search typed in the header stays in force: these
                         fields narrow it, they do not replace it. --}}
                    @if (($filters['search'] ?? null) !== null)
                        <input type="hidden" name="q" value="{{ $filters['search'] }}">
                    @endif
                    @foreach ($filters['maturities'] ?? [] as $maturity)
                        <input type="hidden" name="maturity[]" value="{{ $maturity }}">
                    @endforeach

                    <div class="space-y-3">
                        <div class="refine-field">
                            <label for="f-editor">{{ __('Éditeur') }}</label>
                            <input class="input" type="search" id="f-editor" name="editor" value="{{ $filters['editor'] ?? '' }}" placeholder="cap-rel">
                        </div>

                        <div class="refine-field">
                            <label for="f-project">{{ __('Projet') }}</label>
                            <input class="input" type="search" id="f-project" name="project" value="{{ $filters['project'] ?? '' }}" placeholder="dolinews">
                        </div>

                        <div class="refine-field">
                            <label for="f-dolibarr">{{ __('Concerne Dolibarr') }}</label>
                            {{-- The version filter targets announcements, never
                                 modules (D1): the service never states the
                                 current state of a module, only what was
                                 announced. --}}
                            <select class="input" id="f-dolibarr" name="dolibarr">
                                <option value="">{{ __('toutes versions') }}</option>
                                @foreach ($dolibarrMajors as $major)
                                    <option value="{{ $major }}" @selected((int) ($filters['dolibarr'] ?? 0) === $major)>v{{ $major }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="refine-field">
                            <label for="f-focus">{{ __('Focus') }}</label>
                            <select class="input" id="f-focus" name="focus">
                                <option value="">{{ __('tous') }}</option>
                                @foreach ($focusList as $focus)
                                    <option value="{{ $focus->value }}" @selected(($filters['focus'] ?? null) === $focus->value)>{{ $focus->label() }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- No language filter here: the feed follows the
                             interface language, chosen once in the header. --}}
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <button type="submit" class="btn btn-sm btn-primary">{{ __('Filtrer') }}</button>
                        @if (request()->query())
                            <a class="btn btn-sm btn-ghost" href="{{ route('home') }}">{{ __('Tout afficher') }}</a>
                        @endif
                    </div>
                </form>
            </div>
        </aside>

        <div class="min-w-0 lg:col-start-1 lg:row-start-1">
            @if ($matchingProjects->isNotEmpty())
                <div class="card mb-6">
                    <div class="card-body">
                        <h2 class="card-title">{{ __('Fiches correspondantes') }}</h2>
                        <ul class="mt-3 space-y-2 text-sm">
                            @foreach ($matchingProjects as $project)
                                <li>
                                    <a class="link font-medium" href="{{ route('projects.show', $project->slug) }}">{{ $project->name }}</a>
                                    <span class="text-slate-500 dark:text-slate-400">- {{ $project->editor->name }}</span>
                                    <p class="text-slate-600 dark:text-slate-300">{{ $project->summary }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            {{-- Two columns of cards once the feed is wide enough for two
                 readable ones; the order stays published_at descending, read
                 along the rows. --}}
            <div class="grid gap-4 2xl:grid-cols-2">
                @forelse ($groups as $group)
                    @php($article = $group->lead)
                    @php($sourceLogo = $article->sourceLogo())
                    <article class="card flex flex-col overflow-hidden">
                        <div class="card-body flex-1">
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
                @empty
                    <div class="card 2xl:col-span-2">
                        <div class="card-body py-12 text-center">
                            {{-- An empty search says WHY it is empty: a reader who
                                 reads "no result" concludes the service is empty,
                                 when the answer is that this editor does not
                                 publish here yet. The two are not the same, and
                                 only the second gives them something to do. --}}
                            @if (($filters['search'] ?? null) !== null)
                                <p class="text-slate-500 dark:text-slate-400">
                                    {{ __('Aucune annonce ne correspond à cette recherche.') }}
                                </p>
                                <p class="mt-2 text-slate-500 dark:text-slate-400">
                                    {{ __('Le module que vous cherchez existe peut-être sans que son éditeur publie ici : le service ne dit que ce qui lui a été annoncé.') }}
                                </p>
                                <p class="mt-2">
                                    <a class="link" href="{{ route('pages.editor-guide') }}">{{ __('Vous êtes cet éditeur ?') }}</a>
                                    <span class="text-slate-400 dark:text-slate-500">-</span>
                                    <a class="link" href="{{ route('home') }}">{{ __('Tout afficher') }}</a>
                                </p>
                            @elseif (request()->query())
                                <p class="text-slate-500 dark:text-slate-400">{{ __('Aucune annonce ne correspond à ces filtres.') }}</p>
                                <a class="link mt-2 inline-block" href="{{ route('home') }}">{{ __('Tout afficher') }}</a>
                            @else
                                {{-- No filter set and still nothing: the feed is
                                     genuinely empty now, since an announcement
                                     with no version in this language is shown in
                                     its source language rather than hidden. --}}
                                <p class="text-slate-500 dark:text-slate-400">{{ __('Aucune annonce publiée pour l\'instant.') }}</p>
                            @endif
                        </div>
                    </div>
                @endforelse
            </div>

            <div class="mt-6">
                {{ $articles->links() }}
            </div>
        </div>
    </div>
@endsection

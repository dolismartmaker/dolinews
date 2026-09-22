{{-- Short form of an announcement, as listed on a project sheet or an editor
     page: the title, its date, its summary. The dated feed of the home page
     carries the full form, with the badges the filters act on. --}}
<article class="flex items-start justify-between gap-4 border-t border-slate-100 py-4 first:border-t-0 first:pt-0 last:pb-0 dark:border-slate-800">
    <div class="min-w-0">
        <h3 class="font-medium">
            <a class="hover:text-accent-700 dark:hover:text-accent-300" href="{{ \App\Domain\Dolinews\Seo\ArticleUrl::for($article) }}">
                {{ $article->title }}
            </a>
        </h3>
        <p class="mt-1 text-sm text-slate-700 dark:text-slate-200">{{ $article->summary }}</p>
    </div>

    {{-- Same flag as the feed, one size down: these lists sit in a narrower
         column and the date is a landmark here, not the reading order. --}}
    @include('partials.date-flag', ['date' => $article->published_at, 'compact' => true])
</article>

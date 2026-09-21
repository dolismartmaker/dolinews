{{-- Short form of an announcement, as listed on a project sheet or an editor
     page: the title, its date, its summary. The dated feed of the home page
     carries the full form, with the badges the filters act on. --}}
<article class="border-t border-slate-100 py-4 first:border-t-0 first:pt-0 last:pb-0 dark:border-slate-800">
    <h3 class="font-medium">
        <a class="hover:text-accent-700 dark:hover:text-accent-300" href="{{ route('articles.show', $article) }}">
            {{ $article->title }}
        </a>
    </h3>
    <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
        <time datetime="{{ $article->published_at?->toDateString() }}">{{ $article->published_at?->format('d/m/Y') }}</time>
    </p>
    <p class="mt-1 text-sm text-slate-700 dark:text-slate-200">{{ $article->summary }}</p>
</article>

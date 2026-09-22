{{-- The feed's pagination, in the service's own wording and stylesheet.

     Laravel's own Tailwind view reads "Showing 1 to 25 of 38 results",
     built from four bare keys - Showing, to, of, results. French being
     the source language here, those keys have no French file to answer
     from, so the sentence stayed in English on a site translated into
     ten languages. One sentence with named parameters translates once,
     and reads like a sentence in every one of them.

     pagination.previous and pagination.next come from the framework's
     own files, which the ten languages already carry. --}}
@if ($paginator->hasPages())
    <nav class="mt-8 flex flex-col items-center gap-3 border-t border-slate-200 pt-6 text-sm dark:border-slate-800"
         role="navigation" aria-label="{{ __('Navigation dans les pages') }}">
        <p class="text-slate-500 dark:text-slate-400">
            {{ __('Annonces :first à :last sur :total', [
                'first' => $paginator->firstItem(),
                'last' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ]) }}
        </p>

        <ul class="flex flex-wrap items-center justify-center gap-1">
            <li>
                @if ($paginator->onFirstPage())
                    <span class="rounded-lg px-3 py-2 text-slate-400 dark:text-slate-600" aria-disabled="true">{!! __('pagination.previous') !!}</span>
                @else
                    <a class="rounded-lg px-3 py-2 font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
                       href="{{ $paginator->previousPageUrl() }}" rel="prev">{!! __('pagination.previous') !!}</a>
                @endif
            </li>

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span class="px-2 py-2 text-slate-400 dark:text-slate-600">{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <li>
                            @if ($page == $paginator->currentPage())
                                <span class="rounded-lg bg-slate-100 px-3 py-2 font-semibold dark:bg-slate-800" aria-current="page">{{ $page }}</span>
                            @else
                                <a class="rounded-lg px-3 py-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
                                   href="{{ $url }}" aria-label="{{ __('Aller à la page :page', ['page' => $page]) }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            <li>
                @if ($paginator->hasMorePages())
                    <a class="rounded-lg px-3 py-2 font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
                       href="{{ $paginator->nextPageUrl() }}" rel="next">{!! __('pagination.next') !!}</a>
                @else
                    <span class="rounded-lg px-3 py-2 text-slate-400 dark:text-slate-600" aria-disabled="true">{!! __('pagination.next') !!}</span>
                @endif
            </li>
        </ul>
    </nav>
@endif

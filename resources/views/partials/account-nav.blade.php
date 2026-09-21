{{-- Tabs of the account area. The four screens were only reachable from a row
     of buttons at the bottom of one of them, so an author landing on the token
     page had no way back to the others. --}}
@php
    $tabs = [
        ['route' => 'account.show', 'match' => 'account.show', 'label' => __('Mon compte')],
        ['route' => 'account.contribute', 'match' => 'account.contribute*', 'label' => __('Ma contribution')],
        ['route' => 'account.articles', 'match' => 'account.articles*', 'label' => __('Mes articles')],
        ['route' => 'account.tokens', 'match' => 'account.tokens*', 'label' => __('Jetons d\'API')],
    ];

    foreach ($tabs as $index => $tab) {
        $tabs[$index]['active'] = request()->routeIs($tab['match']);
    }
@endphp

<nav class="mb-6 flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-800" aria-label="{{ __('Mon compte') }}">
    @foreach ($tabs as $tab)
        <a href="{{ route($tab['route']) }}"
           @if ($tab['active']) aria-current="page" @endif
           class="-mb-px border-b-2 px-3 py-2 text-sm font-medium transition {{ $tab['active'] ? 'border-accent-600 text-accent-800 dark:border-accent-400 dark:text-accent-200' : 'border-transparent text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>

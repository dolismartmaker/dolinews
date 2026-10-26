{{-- Header of the public layout: brand, search, the switches of skin, theme
     and language, the account links, and the sections of the site.

     Opaque background and no backdrop filter: a translucent sticky bar is
     repainted on every scroll step, for nothing (LARAVEL_PAGES_PUBLIQUES 3). --}}
<header class="print-hidden sticky top-0 z-40 border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-2 gap-y-2 px-4 py-3">
        <a href="{{ route('home') }}" class="mr-2 shrink-0 text-lg font-semibold tracking-tight">
            DoliNews
        </a>

        @include('partials.header-search')

        <span class="flex-1"></span>

        @include('partials.skin-switch')
        @include('partials.theme-switch')
        @include('partials.locale-switch')

        <div class="hidden items-center gap-1 lg:flex">
            @auth
                <a href="{{ route('account.show') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">
                    {{ __('Mon compte') }}
                </a>
                @if (auth()->user()?->canAccessAdmin())
                    <a href="{{ route('admin.dashboard') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">
                        {{ __('Administration') }}
                    </a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline">{{ __('Déconnexion') }}</button>
                </form>
            @else
                <a href="{{ route('login') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">
                    {{ __('Connexion') }}
                </a>
                <a href="{{ route('register') }}" class="btn btn-sm btn-primary">{{ __('Inscription') }}</a>
            @endauth
        </div>

        {{-- Disclosure menu below lg, and a details element rather than a
             button: the public pages carry no JavaScript, so a button
             would toggle nothing. --}}
        <details class="relative lg:hidden">
            <summary class="btn btn-sm btn-outline list-none">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
                <span class="sr-only">{{ __('Menu') }}</span>
            </summary>

            <div class="absolute right-0 z-50 mt-2 w-64 space-y-1 rounded-xl border border-slate-200 bg-white p-2 shadow-lg dark:border-slate-700 dark:bg-slate-900">
                <div class="md:hidden">
                    @include('partials.public-nav', ['mode' => 'stack'])
                </div>

                <div class="mt-1 border-t border-slate-200 pt-1 dark:border-slate-700">
                    @auth
                        <a href="{{ route('account.show') }}" class="block rounded-lg px-3 py-2 text-base font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Mon compte') }}</a>
                        @if (auth()->user()?->canAccessAdmin())
                            <a href="{{ route('admin.dashboard') }}" class="block rounded-lg px-3 py-2 text-base font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Administration') }}</a>
                        @endif
                        <form method="POST" action="{{ route('logout') }}" class="px-1 pt-1">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline w-full">{{ __('Déconnexion') }}</button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="block rounded-lg px-3 py-2 text-base font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Connexion') }}</a>
                        <a href="{{ route('register') }}" class="block rounded-lg px-3 py-2 text-base font-medium text-accent-700 hover:bg-slate-100 dark:text-accent-300 dark:hover:bg-slate-800">{{ __('Inscription') }}</a>
                    @endauth
                </div>
            </div>
        </details>
    </div>

    {{-- A row of its own under the search, as on a news front page: the
         search takes the width the sections would otherwise share. --}}
    <nav class="mx-auto hidden max-w-6xl items-center gap-1 overflow-x-auto px-4 pb-2 md:flex" aria-label="{{ __('Navigation principale') }}">
        @include('partials.public-nav', ['mode' => 'bar'])
    </nav>
</header>

{{-- Masthead of the compact skin: a rule in the accent colour, then one line
     of text links that wraps on a narrow screen. No disclosure menu: every
     section and every account link stays a visible link at any width.

     Not sticky: a dense page is read top to bottom, and a bar that follows
     the scroll takes back the height the skin saves. --}}
<header class="print-hidden border-t-4 border-b border-t-accent-700 border-b-slate-200 bg-slate-50 dark:border-b-slate-800 dark:bg-slate-900">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-4 gap-y-1 px-4 py-1.5">
        <a href="{{ route('home') }}" class="shrink-0 font-bold text-accent-800 dark:text-accent-300">
            DoliNews
        </a>

        <nav class="inline-links flex flex-wrap items-center text-[13px]" aria-label="{{ __('Navigation principale') }}">
            @include('partials.public-nav', ['mode' => 'text'])
        </nav>

        <span class="flex-1"></span>

        @include('partials.header-search', ['formClass' => 'w-full sm:w-56'])

        <div class="site-switches flex items-center">
            @include('partials.skin-switch')
            @include('partials.theme-switch')
            @include('partials.locale-switch')
        </div>

        <div class="inline-links flex flex-wrap items-center text-[13px]">
            @auth
                <a class="link" href="{{ route('account.show') }}">{{ __('Mon compte') }}</a>
                @if (auth()->user()?->canAccessAdmin())
                    <a class="link" href="{{ route('admin.dashboard') }}">{{ __('Administration') }}</a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="link cursor-pointer">{{ __('Déconnexion') }}</button>
                </form>
            @else
                <a class="link" href="{{ route('login') }}">{{ __('Connexion') }}</a>
                <a class="link font-bold" href="{{ route('register') }}">{{ __('Inscription') }}</a>
            @endauth
        </div>
    </div>
</header>

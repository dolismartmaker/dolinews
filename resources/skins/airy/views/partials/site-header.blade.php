{{-- Masthead of the airy skin: tall, no rule and no background of its own.
     The brand, then the sections in light text, then the search as a single
     underline, the switches and the account links. Every entry is a visible
     link at any width, wrapping on a narrow screen rather than folding into
     a menu.

     Not sticky: a bar that follows the scroll is the one block that never
     leaves the screen. --}}
<header class="print-hidden">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-10 gap-y-5 px-6 pt-10 pb-8">
        <a href="{{ route('home') }}" class="shrink-0 text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">
            DoliNews
        </a>

        <nav class="flex flex-wrap items-center gap-x-6 gap-y-2 text-[15px]" aria-label="{{ __('Navigation principale') }}">
            @include('partials.public-nav', ['mode' => 'text'])
        </nav>

        <span class="hidden flex-1 lg:block"></span>

        <div class="flex w-full flex-wrap items-center gap-x-6 gap-y-3 lg:w-auto">
            @include('partials.header-search', ['formClass' => 'w-full sm:w-64'])

            <div class="flex items-center">
                @include('partials.skin-switch')
                @include('partials.theme-switch')
                @include('partials.locale-switch')
            </div>

            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-[15px]">
                @auth
                    <a class="text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white" href="{{ route('account.show') }}">{{ __('Mon compte') }}</a>
                    @if (auth()->user()?->canAccessAdmin())
                        <a class="text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white" href="{{ route('admin.dashboard') }}">{{ __('Administration') }}</a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="cursor-pointer text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">{{ __('Déconnexion') }}</button>
                    </form>
                @else
                    <a class="text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white" href="{{ route('login') }}">{{ __('Connexion') }}</a>
                    <a class="btn btn-sm btn-primary" href="{{ route('register') }}">{{ __('Inscription') }}</a>
                @endauth
            </div>
        </div>
    </div>
</header>

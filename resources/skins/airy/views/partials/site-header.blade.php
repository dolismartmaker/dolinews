{{-- Masthead of the airy skin: one line, across the page. The brand, the
     search (in the head of every public page, SPEC 6.1), the switches and the
     account links. The sections of the site are not here: they speak to
     editors more than to readers, and lead the footer instead (SPEC 6.6).

     Not sticky: a bar that follows the scroll is the one block that never
     leaves the screen. --}}
<header class="print-hidden">
    <div class="flex w-full flex-wrap items-center gap-x-8 gap-y-4 px-6 py-6 lg:px-12">
        <a href="{{ route('home') }}" class="shrink-0 text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">
            DoliNews
        </a>

        @include('partials.header-search', ['formClass' => 'order-last w-full sm:order-none sm:w-72'])

        <span class="hidden flex-1 sm:block"></span>

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
</header>

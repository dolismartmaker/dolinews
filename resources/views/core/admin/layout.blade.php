<!DOCTYPE html>
{{-- The theme class drives the dark variant of app.css: "dark" when the
     visitor asked for it, "theme-auto" when they follow their system, and
     nothing at all when they asked for light. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $themeClass ?? 'theme-auto' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- $title and not @yield: Livewire renders its components into $slot, so
         the sections of an @extends layout would never be filled and every
         screen would wear the same title. --}}
    <title>{{ $title ?? __('Administration') }} - DoliNews</title>
    @vite(['resources/css/app.css'])
    @livewireStyles
</head>
{{-- pt-8 clears the impersonation banner, which is fixed so that it stays
     reachable at the bottom of a long list: leaving a borrowed session must
     never require going back to the top of the page. --}}
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100 @impersonating('web') pt-8 @endImpersonating">
    {{-- Impersonation is a state an administrator must never lose sight of:
         the banner stays on every screen for as long as the session is
         borrowed, and carries the only way out. --}}
    @impersonating('web')
        @php($impersonated = auth('web')->user())
        <div class="fixed inset-x-0 top-0 z-50 flex h-8 items-center justify-center gap-3 bg-amber-500 px-4 text-xs font-semibold text-white sm:text-sm">
            <span class="truncate">
                {{ __('Session empruntée :') }} {{ $impersonated?->name ?? $impersonated?->email }}
            </span>
            {{-- A POST: leaving an impersonation changes the session, it is
                 not a navigation. --}}
            <form method="POST" action="{{ route('admin.impersonate.leave') }}">
                @csrf
                <button type="submit" class="cursor-pointer underline hover:text-amber-100">
                    {{ __('Revenir à mon compte') }}
                </button>
            </form>
        </div>
    @endImpersonating

    <div class="flex min-h-screen">
        <aside class="hidden w-64 shrink-0 flex-col border-r border-slate-200 bg-white lg:flex dark:border-slate-800 dark:bg-slate-900">
            <div class="flex h-16 items-center border-b border-slate-200 px-6 dark:border-slate-800">
                <a href="{{ route('admin.dashboard') }}" class="text-base font-semibold tracking-tight">
                    DoliNews
                    <span class="block text-xs font-normal text-slate-500 dark:text-slate-400">{{ __('Administration') }}</span>
                </a>
            </div>

            <div class="flex-1 overflow-y-auto p-3">
                @include('partials.admin-nav', ['mode' => 'sidebar'])
            </div>

            <div class="border-t border-slate-200 p-4 text-sm dark:border-slate-800">
                <p class="truncate font-medium">{{ auth('web')->user()?->name }}</p>
                <p class="truncate text-slate-500 dark:text-slate-400">{{ auth('web')->user()?->email }}</p>

                <div class="mt-3 -ml-2.5">
                    @include('partials.theme-switch')
                </div>

                <div class="mt-2 flex items-center gap-3">
                    <a class="link text-sm" href="{{ route('account.show') }}">{{ __('Mon compte') }}</a>
                    {{-- A real POST and not a link: dropping the session is not
                         a navigation. --}}
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button type="submit" class="link cursor-pointer text-sm">{{ __('Déconnexion') }}</button>
                    </form>
                </div>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            {{-- The mobile bar sticks below the impersonation banner rather
                 than to the top of the window, otherwise it slides under it. --}}
            <header class="sticky @impersonating('web') top-8 @else top-0 @endImpersonating z-40 flex h-14 items-center justify-between border-b border-slate-200 bg-white px-4 lg:hidden dark:border-slate-800 dark:bg-slate-900">
                <a href="{{ route('admin.dashboard') }}" class="font-semibold">DoliNews</a>
                <span class="truncate text-xs text-slate-500 dark:text-slate-400">{{ auth('web')->user()?->email }}</span>
            </header>

            {{-- Session flash messages. This layout only renders on a full page
                 load: after a Livewire action only the component comes back, so
                 a component flashing without redirecting must render it too. --}}
            @if (session('status') || session('error'))
                <div class="space-y-3 px-4 pt-4 lg:px-8 lg:pt-6">
                    @if (session('status'))
                        <div class="alert alert-success">{{ session('status') }}</div>
                    @endif
                    @if (session('error'))
                        <div class="alert alert-error">{{ session('error') }}</div>
                    @endif
                </div>
            @endif

            <main class="flex-1 px-4 py-6 pb-24 lg:px-8 lg:pb-8">
                {{ $slot ?? '' }}
            </main>

            <nav class="fixed inset-x-0 bottom-0 z-40 flex border-t border-slate-200 bg-white lg:hidden dark:border-slate-800 dark:bg-slate-900">
                @include('partials.admin-nav', ['mode' => 'bottom'])
            </nav>
        </div>
    </div>

    {{-- Toasts raised by $this->dispatch('notify', message: ...). Without this
         listener the event is dispatched into the void. --}}
    <div id="admin-toasts" class="fixed right-4 bottom-24 z-50 flex w-72 flex-col gap-2 lg:bottom-6"></div>

    {{-- data-navigate-once: without it Livewire replays every body script on
         each navigation, stacking one listener per visit and showing the same
         toast several times. --}}
    <script data-navigate-once>
        window.addEventListener('notify', function (event) {
            var container = document.getElementById('admin-toasts');
            if (!container) return;
            var detail = event.detail || {};
            var toast = document.createElement('div');
            toast.className = detail.level === 'error'
                ? 'alert alert-error shadow-lg'
                : 'alert alert-success shadow-lg';
            toast.textContent = detail.message || '';
            container.appendChild(toast);
            // A refusal is read, not glimpsed: it stays twice as long.
            setTimeout(function () { toast.remove(); }, detail.level === 'error' ? 8000 : 4000);
        });
    </script>

    @livewireScripts
</body>
</html>

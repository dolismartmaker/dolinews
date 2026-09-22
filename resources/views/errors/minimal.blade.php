{{--
    The shell of the error pages the service serves itself (404, 500).

    It does not extend layouts.public on purpose. An address no route
    serves never goes through the web middleware group, so the session
    and the shared $errors bag the public layout reads are simply not
    there, and rendering it would fail inside the error handler - the
    one place where a second failure has nowhere left to go.

    So: no session, no navigation menu, no theme switch. The stylesheet
    is the site's own, the wording is translated, and the way out is a
    single link to the feed.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="theme-auto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') - DoliNews</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen flex-col bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <main class="mx-auto flex w-full max-w-2xl flex-1 items-center px-4 py-12">
        <div class="card w-full">
            <div class="card-body sm:p-8">
                <p class="text-sm font-semibold tracking-wider text-slate-400 uppercase dark:text-slate-500">
                    DoliNews
                </p>

                <h1 class="mt-2 text-2xl font-semibold tracking-tight sm:text-3xl">@yield('title')</h1>

                <p class="mt-4 text-slate-600 dark:text-slate-300">@yield('lead')</p>

                <p class="mt-6">
                    <a class="btn btn-primary" href="{{ route('home') }}">{{ __('Retour au fil') }}</a>
                </p>
            </div>
        </div>
    </main>
</body>
</html>

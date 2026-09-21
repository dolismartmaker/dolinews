<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Administration' }}</title>
    @livewireStyles
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; color: #1a1a1a; background: #f4f5f7; }
        header.admin-nav { background: #1f2937; color: #fff; padding: 0.75rem 1.5rem; display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap; }
        header.admin-nav a { color: #e5e7eb; text-decoration: none; font-size: 0.95rem; }
        header.admin-nav a:hover { color: #fff; text-decoration: underline; }
        header.admin-nav .brand { font-weight: bold; font-size: 1.05rem; }
        header.admin-nav .spacer { flex: 1; }
        header.admin-nav .current-user { font-size: 0.85rem; color: #9ca3af; }
        header.admin-nav form { margin: 0; }
        header.admin-nav button { background: #dc2626; color: #fff; border: 0; padding: 0.35rem 0.75rem; border-radius: 4px; cursor: pointer; font-size: 0.85rem; }
        main.admin-main { padding: 1.5rem; max-width: 1100px; margin: 0 auto; }
        .flash { background: #ecfdf5; border: 1px solid #10b981; color: #065f46; padding: 0.6rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
        .impersonate-banner { background: #fef3c7; border: 1px solid #f59e0b; color: #78350f; padding: 0.6rem 1rem; border-radius: 4px; margin-bottom: 1rem; display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .impersonate-banner .impersonate-leave { background: #b45309; color: #fff; border: 0; padding: 0.35rem 0.75rem; border-radius: 4px; cursor: pointer; font-size: 0.85rem; }
        .row-actions { white-space: nowrap; }
        .row-action-btn { background: #4b5563; color: #fff; border: 0; padding: 0.3rem 0.6rem; border-radius: 4px; cursor: pointer; font-size: 0.82rem; margin-right: 0.25rem; }
        .row-action-btn.btn-impersonate { background: #2563eb; }
        table.admin-table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        table.admin-table th, table.admin-table td { text-align: left; padding: 0.55rem 0.75rem; border-bottom: 1px solid #e5e7eb; font-size: 0.9rem; }
        table.admin-table th { background: #f9fafb; }
        table.admin-table th a { color: #1f2937; text-decoration: none; }
        table.admin-table th a:hover { text-decoration: underline; }
        .toolbar { margin-bottom: 1rem; display: flex; gap: 0.5rem; align-items: center; }
        .toolbar input[type="search"] { padding: 0.4rem 0.6rem; border: 1px solid #d1d5db; border-radius: 4px; min-width: 260px; }
        .pagination-wrap { margin-top: 1rem; }
        form.admin-form label { display: block; margin-bottom: 0.25rem; font-weight: 600; font-size: 0.9rem; }
        form.admin-form .field { margin-bottom: 1rem; }
        form.admin-form input, form.admin-form textarea { width: 100%; max-width: 480px; padding: 0.4rem 0.6rem; border: 1px solid #d1d5db; border-radius: 4px; box-sizing: border-box; }
        form.admin-form .error { color: #b91c1c; font-size: 0.82rem; margin-top: 0.2rem; }
        form.admin-form button { background: #2563eb; color: #fff; border: 0; padding: 0.45rem 1rem; border-radius: 4px; cursor: pointer; }
        .login-card { max-width: 360px; margin: 4rem auto; background: #fff; padding: 1.5rem; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .login-card h1 { font-size: 1.2rem; margin-top: 0; }
        .login-card .field { margin-bottom: 1rem; }
        .login-card label { display: block; margin-bottom: 0.25rem; font-weight: 600; font-size: 0.9rem; }
        .login-card input { width: 100%; padding: 0.45rem 0.6rem; border: 1px solid #d1d5db; border-radius: 4px; box-sizing: border-box; }
        .login-card button { width: 100%; background: #2563eb; color: #fff; border: 0; padding: 0.55rem; border-radius: 4px; cursor: pointer; font-size: 0.95rem; }
        .login-card .error { color: #b91c1c; font-size: 0.85rem; margin-top: 0.3rem; }
    </style>
</head>
<body>
    @auth('web')
        @if (auth('web')->user()?->canAccessAdmin())
            <header class="admin-nav">
                <span class="brand">Administration</span>
                <a href="{{ route('admin.dashboard') }}">Tableau de bord</a>
                <a href="{{ route('admin.review') }}">File de revue</a>
                <a href="{{ route('admin.articles') }}">Articles</a>
                <a href="{{ route('admin.projects') }}">Projets</a>
                <a href="{{ route('admin.editors') }}">Editeurs</a>
                <a href="{{ route('admin.users') }}">Utilisateurs</a>
                <a href="{{ route('admin.moderation') }}">Modération</a>
                <a href="{{ route('admin.media') }}">Médias</a>
                <a href="{{ route('admin.api-requests') }}">Appels API</a>
                <span class="spacer"></span>
                <span class="current-user">{{ auth('web')->user()?->email }}</span>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit">Déconnexion</button>
                </form>
            </header>
        @endif
    @endauth

    <main class="admin-main">
        @if (session('status'))
            <div class="flash">{{ session('status') }}</div>
        @endif

        {{-- Impersonation is a state the admin must never lose sight of: the
             banner stays visible on every admin screen for as long as the
             session is borrowed, and carries the only way out. --}}
        @impersonating('web')
            @php
                $impersonated = auth('web')->user();
            @endphp
            <div class="impersonate-banner">
                <span>
                    Vous êtes connecté en tant que
                    <strong>{{ $impersonated?->name ?? $impersonated?->email }}</strong>
                    ({{ $impersonated?->email }}).
                </span>
                <form method="POST" action="{{ route('admin.impersonate.leave') }}">
                    @csrf
                    <button type="submit" class="impersonate-leave">Revenir à l'administration</button>
                </form>
            </div>
        @endImpersonating

        {{ $slot ?? '' }}
        @yield('content')
    </main>

    @livewireScripts
</body>
</html>

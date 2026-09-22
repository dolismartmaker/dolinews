{{--
    The maintenance page, shown while the service is being updated.

    Three constraints shape it, and none is cosmetic:

    1. NO EXTERNAL ASSET. It is prerendered by `artisan down --render` and
       served by public/index.php BEFORE the Composer autoloader, on a
       deployment that is in the middle of rewriting vendor/ and
       public/build. A stylesheet, a font or an image would be requested
       while being replaced, and would 404 exactly when the page is most
       needed. Hence the inline CSS and the inline SVG.
    2. NO TRANSLATION CALL. The page is frozen at the instant `down` runs,
       in whatever locale the CLI held, and no request can negotiate
       anything afterwards. So it says it in French and in English, on the
       same page, rather than guessing wrong for nine communities.
    3. NO JAVASCRIPT. Nothing is bundled at that moment either.

    The returning-soon wording is deliberate too: someone landing here
    wants to know whether they lost something. They did not.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Mise à jour en cours - DoliNews</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f8fafc;
            --card: #ffffff;
            --ink: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --accent: #0f766e;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0b1220;
                --card: #111827;
                --ink: #e5e7eb;
                --muted: #94a3b8;
                --line: #1f2937;
                --accent: #5eead4;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--bg);
            color: var(--ink);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
        }

        .card {
            width: 100%;
            max-width: 40rem;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 1rem;
            padding: 2rem;
            text-align: center;
        }

        h1 {
            margin: 1.5rem 0 0;
            font-size: 1.5rem;
            letter-spacing: -0.01em;
        }

        .lead { margin: 0.75rem 0 0; }

        .en {
            margin: 1.5rem 0 0;
            padding-top: 1.5rem;
            border-top: 1px solid var(--line);
            color: var(--muted);
        }

        .en strong { color: var(--ink); font-weight: 600; }

        .foot {
            margin: 1.5rem 0 0;
            font-size: 0.875rem;
            color: var(--muted);
        }

        svg { display: block; margin: 0 auto; width: 13rem; height: auto; }

        /* The bar says the work is under way. Reduced motion turns it off:
           an animation nobody asked for is not information. */
        .pulse { animation: pulse 1.8s ease-in-out infinite; transform-origin: left center; }

        @keyframes pulse {
            0%, 100% { opacity: 0.35; }
            50% { opacity: 1; }
        }

        @media (prefers-reduced-motion: reduce) {
            .pulse { animation: none; opacity: 0.7; }
        }
    </style>
</head>
<body>
    <main class="card">
        {{-- Three feed entries, the top one being rewritten. --}}
        <svg viewBox="0 0 220 150" role="img" aria-label="Mise à jour en cours">
            <rect x="18" y="96" width="184" height="36" rx="8" fill="none" stroke="var(--line)" stroke-width="2"/>
            <rect x="34" y="108" width="82" height="6" rx="3" fill="var(--line)"/>
            <rect x="34" y="120" width="120" height="5" rx="2.5" fill="var(--line)"/>

            <rect x="18" y="52" width="184" height="36" rx="8" fill="none" stroke="var(--line)" stroke-width="2"/>
            <rect x="34" y="64" width="96" height="6" rx="3" fill="var(--line)"/>
            <rect x="34" y="76" width="140" height="5" rx="2.5" fill="var(--line)"/>

            <rect x="18" y="8" width="184" height="36" rx="8" fill="none" stroke="var(--accent)" stroke-width="2"/>
            <rect x="34" y="20" width="64" height="6" rx="3" fill="var(--accent)"/>
            <rect class="pulse" x="34" y="32" width="150" height="5" rx="2.5" fill="var(--accent)"/>
        </svg>

        <h1>Mise à jour en cours</h1>
        <p class="lead">
            Le service revient dans quelques minutes. Rien n'est perdu : les annonces
            publiées, les flux et les abonnements reviennent avec lui.
        </p>

        <p class="en">
            <strong>Upgrade in progress.</strong>
            The service will be back in a few minutes. Nothing is lost: published
            announcements, feeds and subscriptions come back with it.
        </p>

        @isset($retryAfter)
            <p class="foot">
                Réessayez dans {{ (int) $retryAfter }} secondes - try again in {{ (int) $retryAfter }} seconds.
            </p>
        @endisset
    </main>
</body>
</html>

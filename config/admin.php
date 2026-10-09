<?php

use App\Livewire\Admin\Support\AdminCallbacks;
use Caprel\Admin\Notices\ReadOnlyAccess;
use Caprel\Admin\Notices\SchedulerStalled;
use Caprel\Admin\Notices\UploadLimitsTooLow;

/*
 * Back-office shell shared by the cap-rel applications.
 *
 * Publish this file whole (vendor:publish --tag=admin-config): the merge with
 * the package defaults happens on the top-level keys only, so a published
 * file holding a partial navigation replaces it instead of completing it.
 *
 * No closure anywhere in this file: `php artisan config:cache` serializes it
 * with var_export, and a closure makes the cache refuse to build. A custom
 * visibility rule is therefore written as [Class::class, 'method'].
 */

return [

    // Name shown at the top of the sidebar. Null: config('app.name').
    'brand' => 'DoliNews',

    // Where the brand links to, and where an ended impersonation lands.
    'home_route' => 'admin.dashboard',

    // POST route the "Déconnexion" button submits to.
    'logout_route' => 'admin.logout',

    // Vite entries of the layout. admin.css is published by
    // `vendor:publish --tag=admin-assets` and must be declared in the
    // input list of vite.config.js.
    //
    // No JavaScript entry by default, and never one that starts Alpine
    // (the resources/js/app.js of a Breeze-era project does): the layout
    // already loads @livewireScripts, which starts the Alpine bundled with
    // Livewire. A second instance takes over some components without the
    // Livewire bindings, and the maryUI tables lose their selection and
    // their pagination.
    'assets' => ['resources/css/admin.css'],

    // Prefix and middleware of the routes the package declares itself (the
    // impersonation leave action). "auth" and nothing stricter on purpose:
    // during an impersonation the signed-in account is the target, and an
    // administration guard would lock out the very person who needs to leave.
    'route_prefix' => 'admin',
    'route_middleware' => ['web', 'auth'],

    /*
     * Who is shown the server-side fix of a notice (the crontab line, the
     * php.ini directives): an access rule, same keys as a menu entry.
     */
    // No spatie roles here: the access model is the moderator and super
    // admin columns of SPEC 4.1.
    'operators' => ['when' => [AdminCallbacks::class, 'isSuperAdmin']],

    // Sources of the standing notices at the top of every admin page,
    // classes implementing Caprel\Admin\Notices\NoticeProvider.
    'notices' => [
        SchedulerStalled::class,
        UploadLimitsTooLow::class,
        ReadOnlyAccess::class,
    ],

    // The package beats every minute through the scheduler; silence longer
    // than this means the scheduler has stopped. Checked only in these
    // environments: a development checkout runs no scheduler.
    'scheduler' => [
        'max_silence_minutes' => 5,
        'environments' => ['production'],
        'cache_store' => null,
    ],

    // The biggest upload a screen of the application offers ("20M"). Null:
    // no upload, no check.
    'uploads' => [
        'expected' => null,
    ],

    // [Class::class, 'method'] answering, for a user, whether it can only
    // read. Null: no read-only notice.
    'read_only' => null,

    // The language switcher of the sidebar. "route" is the application's
    // route changing the language, taking the code as "parameter"; the
    // package only links to it. "list" is code => the language's own name,
    // null for config('app.available_locales'). No route: no switcher.
    // "list" is filled at boot from dolinews.locale_names (AppServiceProvider),
    // the single list of the endonyms the public switch shows as well.
    'locales' => [
        'route' => 'locale.switch',
        'parameter' => 'locale',
        'list' => null,
    ],

    // Where the start and the end of an impersonation are traced, besides
    // the log. "trace" is a static [Class::class, 'method'] taking
    // (Model $actor, string $description, array $properties), for an
    // application keeping its own audit table. Null: spatie/laravel-activitylog
    // when installed, nothing else.
    'impersonation' => [
        'trace' => [AdminCallbacks::class, 'traceImpersonation'],
    ],

    // Class implementing Caprel\Admin\PersonalData\PersonalDataProvider:
    // turns on the "Mes données" screen (route admin.personal-data), where an
    // account downloads and erases its data. Null: no screen.
    'personal_data' => null,

    /*
     * The sidebar. A list of entries and sections, in display order.
     *
     * Entry:
     *   [
     *     'label' => 'Utilisateurs',          // passed through __()
     *     'route' => 'admin.users.index',     // route name of the link
     *     'icon' => 'o-users',                // heroicon, as maryUI names them
     *     'active' => 'admin.users.*',        // routeIs() pattern, default: route
     *     'role' => 'superAdmin',             // optional, string or list (any of)
     *     'permission' => 'show User',        // optional, string or list (any of)
     *     'when' => [Policy::class, 'm'],     // optional, static method (User): bool
     *     'badge' => [Counts::class, 'm'],    // optional, static method (User): int, shown when > 0
     *   ]
     *
     * Section: ['section' => 'Ce serveur', 'items' => [entries...]] with the
     * same optional role / permission / when keys, applied to the whole
     * section. A section left without a visible entry is not shown.
     */
    'navigation' => [
        ['label' => 'Tableau de bord', 'route' => 'admin.dashboard', 'icon' => 'o-home'],
        [
            'label' => 'File de revue',
            'route' => 'admin.review.index',
            'icon' => 'o-inbox-arrow-down',
            // Reading a submission keeps the queue lit: same place, one
            // level down.
            'active' => 'admin.review.*',
            'badge' => [AdminCallbacks::class, 'pendingReviews'],
        ],
        [
            'section' => 'Contenus',
            'items' => [
                ['label' => 'Articles', 'route' => 'admin.articles.index', 'icon' => 'o-document-text', 'active' => 'admin.articles.*'],
                ['label' => 'Fiches projet', 'route' => 'admin.projects.index', 'icon' => 'o-cube', 'active' => 'admin.projects.*'],
                ['label' => 'Éditeurs', 'route' => 'admin.editors.index', 'icon' => 'o-building-office', 'active' => 'admin.editors.*'],
                ['label' => 'Médias', 'route' => 'admin.media.index', 'icon' => 'o-photo', 'active' => 'admin.media.*'],
            ],
        ],
        [
            'section' => 'Le service',
            'items' => [
                ['label' => 'Comptes', 'route' => 'admin.users.index', 'icon' => 'o-users', 'active' => 'admin.users.*'],
                [
                    'label' => 'Signalements',
                    'route' => 'admin.reports.index',
                    'icon' => 'o-flag',
                    'active' => 'admin.reports.*',
                    'badge' => [AdminCallbacks::class, 'openReports'],
                ],
                ['label' => 'Journal de modération', 'route' => 'admin.moderation.index', 'icon' => 'o-shield-check', 'active' => 'admin.moderation.*'],
                ['label' => 'Appels API', 'route' => 'admin.api-requests.index', 'icon' => 'o-signal', 'active' => 'admin.api-requests.*'],
            ],
        ],
        // The public site and the account are one click away: a moderator
        // checks what a reader actually sees after publishing.
        ['label' => 'Voir le fil public', 'route' => 'home', 'icon' => 'o-globe-alt'],
        ['label' => 'Mon compte', 'route' => 'account.show', 'icon' => 'o-user-circle'],
    ],

];

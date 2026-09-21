<?php

/*
 * Copyright (C) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/*
 * Deployment facts read by `php artisan install:doctor` and by the install:*
 * commands that render the system files of deploy/.
 *
 * Everything those commands need to know about a deployment lives here and
 * nowhere in them, which is what lets the same command serve every project of
 * the park, and lets the tests point the checks at a temporary directory
 * instead of /etc.
 *
 * Publish it with:
 *
 *     php artisan vendor:publish --tag=ops-config
 *
 * Publish it WHOLE: the merge with these defaults happens on the top-level
 * keys only, so a published file holding a partial "cron" section replaces the
 * whole section rather than completing it.
 *
 * No path below may name a fixed application directory: /var/www/<app> written
 * anywhere here is wrong on every deployment installed elsewhere. The
 * templates hold {{APP_PATH}} and {{LOG_DIR}}, which the install:* commands
 * resolve from the running checkout.
 */

use Illuminate\Support\Str;

/*
 * Name shared by every generated system file: /etc/cron.d/<slug>,
 * <slug>-worker under Supervisor, <slug>-honeypot for logrotate and fail2ban.
 * Derived from APP_NAME like the install:* commands do, so the doctor looks at
 * the files they actually write.
 */
$slug = Str::slug((string) env('OPS_SLUG', (string) env('APP_NAME', 'Laravel')));

return [

    'slug' => $slug,

    /*
     * Which process wakes the Laravel scheduler up, and there is only ever one:
     *
     *   'cron'       an entry in /etc/cron.d calling schedule:run every minute
     *   'supervisor' a schedule:work program alongside the queue worker
     *
     * Both are legitimate, and the choice is a deployment fact rather than a
     * preference: an application whose host has no cron, or which would rather
     * have Supervisor restart its scheduler, sets this to 'supervisor'.
     *
     * What it changes: the doctor stops asking for the cron entry it would
     * never find and asks for the Supervisor program instead, reads the right
     * log for the freshness check, and install:cron refuses to run rather than
     * install the entry that would make every scheduled task run twice.
     *
     * What it does NOT change: the single-scheduler check. Declaring one
     * topology does not prevent the other from being installed by hand, and
     * that pair is precisely what must never exist.
     */
    'scheduler' => env('OPS_SCHEDULER', 'cron'),

    'cron' => [
        // Versioned template, relative to the application root.
        'template' => 'deploy/cron/scheduler.cron',
        'directory' => env('OPS_CRON_DIR', '/etc/cron.d'),
        'target' => env('OPS_CRON_TARGET', '/etc/cron.d/'.$slug),
        /*
         * Where the scheduler writes. Under 'supervisor', point this at the
         * stdout_logfile of the schedule:work program instead: it is the file
         * whose freshness proves the scheduler still runs.
         */
        'log' => storage_path('logs/cron.log'),
        /*
         * schedule:run writes one line per minute, even with nothing due, so a
         * log older than this means cron stopped waking Laravel up.
         *
         * Under 'supervisor' the meaning differs and the value must follow:
         * schedule:work writes only when a task actually runs, so the threshold
         * comes from the most frequent task of the project, not from this
         * default.
         */
        'max_age_minutes' => 5,
    ],

    'supervisor' => [
        // Versioned template, relative to the application root.
        'source' => 'deploy/supervisor/worker.conf',
        'target' => env('OPS_SUPERVISOR_TARGET', '/etc/supervisor/conf.d/'.$slug.'-worker.conf'),
        // Also scanned to detect a second scheduler running next to the cron entry.
        'directory' => env('OPS_SUPERVISOR_DIR', '/etc/supervisor/conf.d'),
    ],

    /*
     * The two logs Laravel does not rotate itself: cron.log, written by the
     * shell cron starts every minute, and honeypot.log, written by the scanner
     * trap. Both templates, both rendered by `install:logrotate`.
     */
    'logrotate' => [
        'directory' => env('OPS_LOGROTATE_DIR', '/etc/logrotate.d'),
        'sources' => [
            'scheduler' => 'deploy/logrotate/scheduler',
            'honeypot' => 'deploy/logrotate/honeypot',
        ],
    ],

    /*
     * Filter and jails of the scanner trap, rendered by `install:fail2ban`.
     * The two go together: a jail naming an absent filter is refused, and a
     * filter alone bans nobody.
     */
    'fail2ban' => [
        'filter_directory' => env('OPS_FAIL2BAN_FILTER_DIR', '/etc/fail2ban/filter.d'),
        'jail_directory' => env('OPS_FAIL2BAN_JAIL_DIR', '/etc/fail2ban/jail.d'),
        'sources' => [
            'filter' => 'deploy/fail2ban/filter.d/honeypot.conf',
            'jail' => 'deploy/fail2ban/jail.d/honeypot.conf',
        ],
    ],

    'queue' => [
        /*
         * A job older than this is not a busy queue, it is a stopped or stuck
         * worker: nothing else keeps a job waiting that long.
         */
        'max_pending_age_minutes' => 15,
    ],

    /*
     * Dependency audit: who gets told when composer audit or npm audit finds
     * something, and which views render the message.
     */
    'security' => [
        // Falls back to the From address of the application when left empty.
        'recipient' => env('OPS_SECURITY_EMAIL'),
        'views' => [
            'html' => 'ops::mail.security-audit',
            'text' => 'ops::mail.security-audit-text',
        ],
    ],

    /*
     * Commands the doctor prints next to a check that failed.
     *
     * They are the commands OF THE PROJECT, not the generic ones: `make
     * migrate` where the Makefile wraps the rights and the --force, `make
     * chownend` rather than a chown copied wrong. A project without these
     * Makefile targets overrides the keys here, and the doctor keeps naming a
     * command that exists.
     */
    'fix_commands' => [
        'cron' => 'make cron',
        'supervisor' => 'make supervisor',
        'queue_status' => 'make queue-status',
        'migrate' => 'make migrate',
        'composer' => 'make composer',
        'cache' => 'make cache',
        'assets' => 'make prod',
        'permissions' => 'make chownend',
    ],

    /*
     * Checked with extension_loaded(). Same list as the server requirements of
     * INSTALL.md, and nothing more: the package default also asks for zip,
     * bcmath and intl, which DoliNews never calls. A doctor that reports a
     * missing extension the application does not use teaches the operator to
     * skip its output.
     */
    'php_extensions' => ['mbstring', 'xml', 'curl', 'gd', 'pdo'],

    // Must be writable by the account running PHP.
    'writable_paths' => [
        'storage',
        'bootstrap/cache',
    ],

    /*
     * Built assets, relative to the application root. Absent means the site
     * serves every page without stylesheet.
     */
    'asset_manifest' => 'public/build/manifest.json',

];

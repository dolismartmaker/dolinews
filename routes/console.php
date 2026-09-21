<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| DoliNews scheduler (SPEC 3.2/5.1/5.2/8/9.6)
|--------------------------------------------------------------------------
|
| Every job is idempotent and safe to re-run. Under cron, see
| ~/docs/laravel/LARAVEL_CRON.md for the supervisor-side wiring.
|
*/

// Contribution index: harvest the reference repositories daily (SPEC 3.2).
Schedule::command('dolinews:harvest-committers')->dailyAt('03:10');

// Orphan media purge: uploads never bound to an article (SPEC 5.2).
Schedule::command('dolinews:purge-orphan-media')->dailyAt('03:40');

// Project link checks, oldest-checked first (SPEC 8).
Schedule::command('dolinews:check-links')->dailyAt('04:10');

// Three-day idle review reminders (SPEC 5.1).
Schedule::command('dolinews:review-reminders')->dailyAt('09:00');

// Auto-cancellation of unconfirmed conflict acts after seven days
// (SPEC 9.6).
Schedule::command('dolinews:expire-moderation-confirmations')->hourly();

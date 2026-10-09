<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| DoliNews scheduler (SPEC 3.2/5.1/5.2/8/9.6)
|--------------------------------------------------------------------------
|
| Every job is idempotent and safe to re-run. Under cron, see
| capdoc:LARAVEL_CRON.md for the supervisor-side wiring.
|
*/

// Contribution index: harvest the reference repositories daily (SPEC 3.2).
Schedule::command('dolinews:harvest-committers')->dailyAt('03:10');

// Subscription links whose delay has run out, and with them the
// addresses typed into the public form that nobody confirmed (SPEC 6.4).
Schedule::command('dolinews:purge-subscription-links')->dailyAt('03:25');

// Orphan media purge: uploads never bound to an article (SPEC 5.2).
Schedule::command('dolinews:purge-orphan-media')->dailyAt('03:40');

// Project link checks, oldest-checked first (SPEC 8).
Schedule::command('dolinews:check-links')->dailyAt('04:10');

// Dolibarr core releases: one submission per new stable version
// (SPEC 5.8). Mid-morning and not at night on purpose - what lands in
// the queue may be a security fix, and it is worth something only if a
// reviewer is in front of their screen when it arrives.
Schedule::command('dolinews:watch-dolibarr-releases')->dailyAt('10:00');

// Three-day idle review reminders (SPEC 5.1).
Schedule::command('dolinews:review-reminders')->dailyAt('09:00');

// Subscription mails, one run per cadence (SPEC 6.4). The instant one
// is a sweep and not a hook on publication: an article back-dated to
// 2019 must mail nobody, and a cursor on published_at rules that out by
// construction where an event would have had to remember it.
Schedule::command('dolinews:send-digests --cadence=instant')->everyFifteenMinutes();
Schedule::command('dolinews:send-digests --cadence=daily')->dailyAt('07:00');
Schedule::command('dolinews:send-digests --cadence=weekly')->weeklyOn(1, '07:00');

// Project sheets the engine has not caught up with (SPEC 5.7): the ones
// missing a language their editor asked for, and the machine versions a
// sheet has moved past since. It writes nothing on a catalogue nobody
// edited, so a daily pass costs nothing - and a sheet is a permanent
// text, which is exactly why it must not stay wrong in nine languages.
// Announcements are not swept this way: each of them is translated when
// it is published.
Schedule::command('dolinews:translate-sheets')->dailyAt('04:40');

// Auto-cancellation of unconfirmed conflict acts after seven days
// (SPEC 9.6).
Schedule::command('dolinews:expire-moderation-confirmations')->hourly();

// Dependency advisories, composer and npm (caprel/laravel-ops). Before the
// working day starts, so the mail is read the morning it is sent.
//
// Its whole value is the deduplication it carries: the same list of
// advisories is mailed once, not every morning until someone gets round to
// it. A daily mail that never changes stops being read within a week, and the
// day a critical advisory appears in it nobody notices.
Schedule::command('ops:security-audit')->dailyAt('06:00');

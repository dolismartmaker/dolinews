<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/**
 * The scheduler declarations (routes/console.php).
 *
 * A scheduled task that never fires is the quietest failure of the lot: cron
 * wakes Laravel every minute, Laravel finds nothing due or no such command,
 * and the only trace is a line in cron.log nobody reads. Six months later the
 * contributor index has never been harvested.
 */

/**
 * @return list<string>
 */
function scheduledCommands(): array
{
    $names = [];

    foreach (app(Schedule::class)->events() as $event) {
        $command = (string) $event->command;

        // The built command looks like: '/usr/bin/php' 'artisan' name
        if (preg_match("/'artisan'\s+'?([^'\s]+)'?/", $command, $matches) === 1) {
            $names[] = $matches[1];
        }
    }

    return $names;
}

it('schedules every DoliNews task and the dependency audit', function (): void {
    expect(scheduledCommands())->toContain(
        'dolinews:harvest-committers',
        'dolinews:purge-orphan-media',
        'dolinews:check-links',
        'dolinews:review-reminders',
        'dolinews:expire-moderation-confirmations',
        'dolinews:send-digests',
        'ops:security-audit',
    );
});

it('schedules one subscription mail run per cadence', function (): void {
    // Three runs and not one command deciding on its own: reading
    // routes/console.php has to be enough to know what leaves the
    // service and when (SPEC 6.4).
    $runs = collect(app(Schedule::class)->events())
        ->filter(static fn ($event): bool => str_contains((string) $event->command, 'dolinews:send-digests'))
        ->map(static fn ($event): string => $event->expression)
        ->values()
        ->all();

    expect($runs)->toHaveCount(3)
        ->and($runs)->toContain('*/15 * * * *')
        ->and($runs)->toContain('0 7 * * *')
        ->and($runs)->toContain('0 7 * * 1');
});

it('schedules no command artisan does not know', function (): void {
    $registered = array_keys(Artisan::all());

    foreach (scheduledCommands() as $name) {
        expect($registered)->toContain($name);
    }
});

it('runs the dependency audit before the working day starts', function (): void {
    // Deduplication is what makes this mail readable: the same advisories are
    // reported once, so the morning one lands is the morning something
    // changed. Sent at noon, it competes with the day's own noise.
    $audit = collect(app(Schedule::class)->events())
        ->first(static fn ($event): bool => str_contains((string) $event->command, 'ops:security-audit'));

    expect($audit)->not->toBeNull()
        ->and($audit->expression)->toBe('0 6 * * *');
});

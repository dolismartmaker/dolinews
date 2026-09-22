<?php

declare(strict_types=1);

use App\Services\HoneypotReporter;
use App\Support\HoneypotMatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * The three honeypot tests that count (~/docs/laravel/LARAVEL_HONEYPOT.md):
 * the real log line against the real failregex, no forged detection, the
 * shipped fail2ban filter and jail parse.
 */
function honeypotLine(): string
{
    // Simulate exactly what Log::channel('honeypot')->warning() writes.
    return '[2026-09-02 03:57:39] local.WARNING: HONEYPOT level=instant ip=203.0.113.5 method=GET path="/.env" host="dolinews.example" ua="Go-http-client/1.1" reason=secret-extension:env';
}

it('matches the shipped fail2ban filter against a real honeypot line', function (): void {
    $filterPath = base_path('deploy/fail2ban/filter.d/honeypot.conf');
    expect($filterPath)->toBeFile();

    $filter = (string) file_get_contents($filterPath);

    // Extract the failregex line and rebuild the pattern the way fail2ban
    // does: <level> substituted, <HOST> as the address group.
    $failregex = '';
    foreach (explode("\n", $filter) as $line) {
        if (str_starts_with(trim($line), 'failregex')) {
            $failregex = trim(explode('=', $line, 2)[1]);
        }
    }

    expect($failregex)->not->toBe('');

    $pattern = str_replace(
        ['<level>', '<HOST>', '\s'],
        ['(?:instant|probable)', '(?<host>\S+)', '\s'],
        $failregex,
    );

    // Without maxlines=1 + a single-line datepattern, fail2ban would
    // anchor the match on the wrong line of a rotated or busy log.
    expect(preg_match('#^.*\sHONEYPOT level=(?:instant|probable) ip=(?<host>\S+)\s#', honeypotLine()))->toBe(1)
        ->and(preg_match('#'.$pattern.'#', honeypotLine()))->toBe(1);
});

it('never matches a line the application did not write', function (): void {
    // A client-controlled value carrying the marker must not produce a
    // matchable line: the reporter neutralises it at write time.
    $request = Request::create('/wp-admin/setup.php', 'GET', [], [], [], [
        'HTTP_USER_AGENT' => 'HONEYPOT HONEYPOT scanner',
        'REMOTE_ADDR' => '203.0.113.9',
    ]);

    $reporter = new HoneypotReporter;
    $line = $reporter->report($request, ['level' => 'probable', 'reason' => 'known-probe:wp-admin/']);

    $log = (string) file_get_contents(storage_path('logs/honeypot.log'));

    // One line per detection: the neutralised marker never forms a second
    // HONEYPOT level=... ip=... sequence inside the user agent field.
    $matches = [];
    preg_match_all('#^.*HONEYPOT level=(?:instant|probable) ip=\S+#m', $log, $matches);

    expect(count($matches[0]))->toBeGreaterThanOrEqual(1)
        ->and($log)->toContain('HONEY_POT HONEY_POT scanner');
});

it('does not ban on paths the application legitimately serves', function (string $path): void {
    Config::set('honeypot.enabled', true);

    expect(HoneypotMatcher::match($path))->toBeNull();
})->with([
    '/feeds.xml',
    '/fr/donnees',
    '/api/v1/articles',
    '/locale/en',
]);

it('ships parseable fail2ban and logrotate templates', function (): void {
    foreach ([
        'deploy/fail2ban/filter.d/honeypot.conf',
        'deploy/fail2ban/jail.d/honeypot.conf',
        'deploy/logrotate/honeypot',
    ] as $path) {
        expect(base_path($path))->toBeFile();
    }

    $jail = (string) file_get_contents(base_path('deploy/fail2ban/jail.d/honeypot.conf'));

    // Both jails: the instant one keeps only certainties, the general one
    // starts banning at the fourth attempt.
    expect($jail)->toContain('[{{APP_SLUG}}-honeypot]')
        ->and($jail)->toContain('[{{APP_SLUG}}-honeypot-instant]')
        ->and($jail)->toContain('maxretry = 4')
        ->and($jail)->toContain('maxretry = 1');
});

it('keeps every deploy file a template, never a ready-to-copy file', function (string $path): void {
    // A path frozen into one of these is the CANT_REREAD incident:
    // supervisorctl refuses the whole file, taking down every program
    // declared in it. logrotate and fail2ban just never run, silently.
    $contents = (string) file_get_contents(base_path($path));

    expect($contents)->toContain('{{')
        ->and($contents)->not->toContain(base_path());
})->with([
    'deploy/cron/scheduler.cron',
    'deploy/supervisor/worker.conf',
    'deploy/logrotate/scheduler',
    'deploy/logrotate/honeypot',
    'deploy/fail2ban/filter.d/honeypot.conf',
    'deploy/fail2ban/jail.d/honeypot.conf',
]);

it('wakes the scheduler every minute, never on a coarser tick', function (): void {
    // Cron only wakes Laravel up; Laravel decides what is due to the
    // minute. Under a */5 entry, dolinews:review-reminders at 09:00 still
    // runs but dolinews:harvest-committers at 03:10 would too, while any
    // later task on a non-multiple of five would never fire at all.
    //
    // Comments are dropped before asserting: the template explains the
    // */5 trap at length, and matching on the whole file would fail on
    // the very lines that warn about it.
    $template = (string) file_get_contents(base_path('deploy/cron/scheduler.cron'));

    $active = implode("\n", array_filter(
        preg_split('/\R/', $template) ?: [],
        static fn (string $line): bool => ! str_starts_with(ltrim($line), '#') && trim($line) !== '',
    ));

    expect($active)->toContain('* * * * * {{USER}}')
        ->and($active)->not->toContain('*/5');
});

it('declares no scheduler program beside the worker', function (): void {
    // One scheduler, never two: a cron entry AND a schedule:work program
    // run every task twice, and a task that writes turns that into an
    // incident rather than waste. The template documents the alternative
    // in comments, which start with a semicolon.
    $worker = (string) file_get_contents(base_path('deploy/supervisor/worker.conf'));

    $active = array_filter(
        preg_split('/\R/', $worker) ?: [],
        static fn (string $line): bool => ! str_starts_with(ltrim($line), ';') && trim($line) !== '',
    );

    expect(implode("\n", $active))->toContain('queue:work')
        ->and(implode("\n", $active))->not->toContain('schedule:work');
});

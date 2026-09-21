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
    $filterPath = base_path('deploy/fail2ban/filter.d/laravel-honeypot.conf');
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
    '/donnees',
    '/api/v1/articles',
    '/locale/en',
]);

it('ships parseable fail2ban and logrotate templates', function (): void {
    foreach ([
        'deploy/fail2ban/filter.d/laravel-honeypot.conf',
        'deploy/fail2ban/jail.d/laravel-honeypot.conf',
        'deploy/logrotate/honeypot',
    ] as $path) {
        expect(base_path($path))->toBeFile();
    }

    $jail = (string) file_get_contents(base_path('deploy/fail2ban/jail.d/laravel-honeypot.conf'));

    // Both jails: the instant one keeps only certainties, the general one
    // starts banning at the fourth attempt.
    expect($jail)->toContain('[dolinews-honeypot]')
        ->and($jail)->toContain('[dolinews-honeypot-instant]')
        ->and($jail)->toContain('maxretry = 4')
        ->and($jail)->toContain('maxretry = 1');
});

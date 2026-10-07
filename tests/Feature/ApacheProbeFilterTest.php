<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

/**
 * The Apache trap catches what the application never sees: a probe for /.env
 * is refused by the web server, so it writes nothing to honeypot.log and the
 * jails of HoneypotTest cannot ban it.
 *
 * Everything here is asserted against the SHIPPED filter, rebuilt the way
 * fail2ban rebuilds it. A test carrying its own copy of the regex would keep
 * passing after the file it is supposed to guard was broken.
 */
function apacheFilterPattern(): string
{
    $filter = (string) file_get_contents(base_path('deploy/fail2ban/filter.d/apache-probe.conf'));

    $values = [];
    foreach (preg_split('/\R/', $filter) ?: [] as $line) {
        if (preg_match('/^(probe|failregex)\s*=\s*(.+)$/', trim($line), $m) === 1) {
            $values[$m[1]] = trim($m[2]);
        }
    }

    expect($values)->toHaveKeys(['probe', 'failregex']);

    // fail2ban substitutes its own definitions, then <HOST>. The date has
    // already been stripped by the detector at that point, which is why the
    // regex has to tolerate an empty bracket pair.
    return str_replace(
        ['<probe>', '<HOST>'],
        [$values['probe'], '(?<host>\S+)'],
        $values['failregex'],
    );
}

/**
 * Real lines of the production access log, with the addresses replaced by
 * documentation ones. Paths and user agents are untouched: they are the thing
 * under test.
 */
function apacheLine(string $request, int $status, string $agent = 'Mozilla/5.0 (compatible; scan)'): string
{
    return sprintf(
        '203.0.113.7 - - [23/Sep/2026:20:12:42 +0200] "%s HTTP/1.1" %d 5750 "-" "%s"',
        $request,
        $status,
        $agent,
    );
}

it('bans the probes Apache refuses before PHP ever sees them', function (string $request, int $status): void {
    expect(preg_match('#'.apacheFilterPattern().'#', apacheLine($request, $status), $m))->toBe(1)
        ->and($m['host'])->toBe('203.0.113.7');
})->with([
    ['GET /.env', 403],
    ['GET /.env.production', 403],
    ['GET /.git/config', 403],
    ['GET /.aws/credentials', 403],
    ['GET /.ssh/id_ed25519', 403],
    ['GET /.git-credentials', 403],
    ['GET /.vscode/sftp.json', 403],
    // Reached PHP, so the honeypot saw these too. The overlap is deliberate:
    // whichever layer answers first, the address is reported once.
    ['GET /api/.env', 404],
    ['GET /files../.env', 404],
    // Encoded traversals arrive with the dot intact.
    ['GET /..%2f.env', 404],
    ['GET /%2e%2e/.env', 400],
    // A dotfile answered 200 is the one case that must not be missed, which is
    // why the filter never looks at the status code.
    ['GET /.env', 200],
    ['POST /.env', 403],
]);

it('leaves alone what the site legitimately serves', function (string $request, int $status): void {
    expect(preg_match('#'.apacheFilterPattern().'#', apacheLine($request, $status)))->toBe(0);
})->with([
    // The day the storage symlink went stale after an rsync, Apache answered
    // 403 to a hundred image requests from real readers. Banning on the status
    // code would have banned them; this is that regression.
    ['GET /storage/media/1/f6/f6d7954c974f6aade376adb04b75f535e97697bf690d632f0d10f9455bb1137a.png', 403],
    ['HEAD /storage/media/1/dd/dd40ed8cf361fc4897484be573a9324d482e53c1c49f20fa6a621f07092fa488.png', 403],
    // The one legitimate dotted segment on the web. Banning it costs a
    // certificate renewal, and Googlebot asks for traffic-advice.
    ['GET /.well-known/acme-challenge/0pQ3nS5xK9', 200],
    ['GET /.well-known/traffic-advice', 403],
    ['GET /.well-known/security.txt', 200],
    ['GET /api/v1/openapi.json', 200],
    ['GET /api/v1/projects/capmail', 404],
    ['GET /feeds.xml', 200],
    ['GET /fr/donnees', 200],
]);

it('dates the lines it reads, instead of falling back to the current time', function (): void {
    // An anchored or bracketed datepattern silently matches nothing on a
    // combined line: fail2ban then dates every hit "now", and the next reload
    // re-reads the tail of the log and bans a batch of addresses whose scan is
    // days old. fail2ban-regex shows it only as an empty "Date template hits".
    $filter = (string) file_get_contents(base_path('deploy/fail2ban/filter.d/apache-probe.conf'));

    expect($filter)->toMatch('/^datepattern = %%d\/%%b\/%%Y:%%H:%%M:%%S %%z$/m');

    // The same pattern, as PHP writes it, against the timestamp Apache writes.
    $date = '23/Sep/2026:20:12:42 +0200';
    expect(DateTime::createFromFormat('d/M/Y:H:i:s O', $date))->not->toBeFalse();
});

it('still matches once fail2ban has stripped the date', function (): void {
    // fail2ban removes the timestamp it recognised and leaves the brackets
    // empty. A filter anchored on the raw line matches during a manual
    // fail2ban-regex run and never in production.
    $stripped = '203.0.113.7 - - [] "GET /.env HTTP/1.1" 403 4573 "-" "Go-http-client/1.1"';

    expect(preg_match('#'.apacheFilterPattern().'#', $stripped))->toBe(1);
});

it('lists exactly the addresses the jail would have banned', function (): void {
    // The backfill script reads the shipped filter instead of carrying its own
    // copy of the regex. This test is what proves the two stay in step: the
    // same log, the same verdict as the failregex above.
    if (shell_exec('printf x | grep -qP x 2>/dev/null && echo ok') === null) {
        test()->markTestSkipped('this grep has no -P (PCRE)');
    }

    $log = (string) tempnam(sys_get_temp_dir(), 'access');
    file_put_contents($log, implode("\n", [
        // Two scanners, one of them twice, and two legitimate readers.
        apacheLine('GET /.env', 403),
        apacheLine('GET /.git/config', 403),
        '198.51.100.2 - - [23/Sep/2026:20:13:01 +0200] "GET /.aws/credentials HTTP/1.1" 403 4573 "-" "curl/8"',
        apacheLine('GET /storage/media/1/f6/deadbeef.png', 403),
        apacheLine('GET /.well-known/traffic-advice', 403),
    ])."\n");

    $script = base_path('scripts/fail2ban-backfill.sh');
    $filter = base_path('deploy/fail2ban/filter.d/apache-probe.conf');

    $output = (string) shell_exec(sprintf(
        '%s %s %s 2>/dev/null',
        escapeshellarg($script),
        escapeshellarg($filter),
        escapeshellarg($log),
    ));

    $addresses = array_values(array_filter(explode("\n", trim($output))));

    // 203.0.113.7 first: two probes against one.
    expect($addresses)->toBe(['203.0.113.7', '198.51.100.2']);

    unlink($log);
});

it('ships a jail that bans on the first probe and names its log', function (): void {
    $jail = (string) file_get_contents(base_path('deploy/fail2ban/jail.d/apache-probe.conf'));

    expect($jail)->toContain('[{{APP_SLUG}}-apache-probe]')
        ->and($jail)->toContain('filter   = {{APP_SLUG}}-apache-probe')
        ->and($jail)->toContain('logpath  = {{ACCESS_LOG}}')
        ->and($jail)->toContain('maxretry = 1');
});

it('renders both files with every placeholder resolved', function (): void {
    $log = tempnam(sys_get_temp_dir(), 'access');
    Config::set('ops.fail2ban.apache.access_log', $log);

    $status = Artisan::call('install:fail2ban-apache', ['--print' => true]);
    $output = Artisan::output();

    expect($status)->toBe(0)
        ->and($output)->toContain('-apache-probe]')
        ->and($output)->toContain($log)
        ->and($output)->not->toContain('{{');

    unlink((string) $log);
});

it('refuses to install a jail whose access log does not exist', function (): void {
    // fail2ban accepts that jail without a word: it starts, reports itself
    // active, and bans nobody while the site is being scanned.
    Config::set('ops.fail2ban.apache.access_log', '/var/log/apache2/no-such-vhost-access.log');

    $root = sys_get_temp_dir().'/fail2ban-'.uniqid();

    $this->artisan('install:fail2ban-apache', ['--root' => $root])
        ->assertFailed();

    expect(is_dir($root))->toBeFalse();
});

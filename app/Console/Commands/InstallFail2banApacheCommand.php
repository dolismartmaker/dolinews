<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Caprel\Ops\Console\AbstractInstallCommand;
use Illuminate\Console\Command;

/**
 * Installs the fail2ban filter and jail catching the probes the application
 * never sees.
 *
 * `install:fail2ban` covers what reaches PHP, which is only half of a scan:
 * /.env, /.git/config and /.aws/credentials are refused by Apache itself, so
 * they leave no line in honeypot.log and ban nobody. This command installs the
 * trap that reads the access log of the vhost instead.
 *
 * Kept apart from the package command rather than folded into it: the two have
 * different sources of truth (one a file we write, one a file the web server
 * writes), and a jail whose logpath is wrong must be able to be reinstalled
 * without touching the one that works.
 */
class InstallFail2banApacheCommand extends AbstractInstallCommand
{
    /**
     * @var string
     */
    protected $signature = 'install:fail2ban-apache'.self::COMMON_OPTIONS;

    /**
     * @var string
     */
    protected $description = 'Render and install the fail2ban filter and jail reading the Apache access log';

    /**
     * @return list<array{source: string, target: string}>
     */
    protected function files(string $slug): array
    {
        return [
            [
                'source' => (string) config('ops.fail2ban.apache.sources.filter'),
                'target' => rtrim((string) config('ops.fail2ban.filter_directory'), '/').'/'.$slug.'-apache-probe.conf',
            ],
            [
                'source' => (string) config('ops.fail2ban.apache.sources.jail'),
                'target' => rtrim((string) config('ops.fail2ban.jail_directory'), '/').'/'.$slug.'-apache-probe.conf',
            ],
        ];
    }

    protected function targetLabel(): string
    {
        return 'fail2ban';
    }

    /**
     * @return array<string, string>
     */
    protected function replacements(string $slug, string $user): array
    {
        return ['{{ACCESS_LOG}}' => $this->accessLog()];
    }

    /**
     * Refuses rather than installs a jail that cannot ban anyone.
     *
     * fail2ban does not report a logpath that does not exist: the jail starts,
     * `fail2ban-client status` shows it active, and the counter stays at zero
     * for weeks while the site is being scanned. The one moment this is
     * catchable is now.
     */
    protected function preflight(string $user): int
    {
        $log = $this->accessLog();

        if (! is_file($log)) {
            $this->error(sprintf('Access log %s does not exist; nothing would ever be read from it.', $log));
            $this->line('Find the one of the vhost with: apachectl -S, then read its CustomLog directive.');
            $this->line('Declare it with OPS_FAIL2BAN_APACHE_ACCESS_LOG=/var/log/apache2/<vhost>-access.log');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param  list<string>  $targets
     */
    protected function reportEnvironment(string $user, array $targets): void
    {
        $slug = $this->slug();

        $this->line(sprintf(
            'Check the regex with: fail2ban-regex %s /etc/fail2ban/filter.d/%s-apache-probe.conf',
            $this->accessLog(),
            (string) $slug,
        ));
        $this->line(sprintf('Then: fail2ban-client reload && fail2ban-client status %s-apache-probe', (string) $slug));
    }

    private function accessLog(): string
    {
        return (string) config('ops.fail2ban.apache.access_log');
    }
}

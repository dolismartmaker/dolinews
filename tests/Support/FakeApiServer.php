<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * A throwaway API served by php -S in the project port window
 * (capdoc:PORTS.md), for the tests that drive the publishing client.
 */
final class FakeApiServer
{
    /**
     * Serve the router on the first port of the window this process holds.
     *
     * The server is asked whether it holds the port rather than the port
     * probed: under the parallel runner, two processes may both see the
     * same port free, and the probe of the one that failed to bind then
     * succeeds on the other's server, which answers with the wrong script.
     *
     * @return array{0: Process, 1: string}|null server and API base URL, null when no port of the window is free
     */
    public static function start(int $base, string $router): ?array
    {
        for ($port = $base + 1; $port <= $base + 4; $port++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

            if ($probe !== false) {
                fclose($probe);

                continue;
            }

            $server = new Process(['php', '-S', '127.0.0.1:'.$port, $router]);
            $server->start();

            $started = false;
            $server->waitUntil(static function (string $type, string $output) use (&$started): bool {
                if (str_contains($output, 'Development Server') && str_contains($output, 'started')) {
                    $started = true;

                    return true;
                }

                return str_contains($output, 'Failed to listen');
            });

            if ($started && $server->isRunning()) {
                return [$server, 'http://127.0.0.1:'.$port.'/api/v1'];
            }

            fwrite(STDERR, 'FakeApiServer: port '.$port.' taken while starting, trying the next one.'.PHP_EOL);
            $server->stop();
        }

        return null;
    }
}

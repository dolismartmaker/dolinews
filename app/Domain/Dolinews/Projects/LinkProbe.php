<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Projects;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * One probe of one project sheet link (SPEC 8).
 *
 * Redirects are followed by hand rather than by the HTTP client: the
 * client follows them silently, so a public URL redirecting to a private
 * address would reach it without the guard ever seeing the target. Here
 * every hop goes back through OutboundUrlGuard.
 */
class LinkProbe
{
    public function __construct(
        private readonly OutboundUrlGuard $guard,
    ) {}

    /**
     * Probe a link and say whether it is broken.
     *
     * A refused target counts as broken: the sheet must stop pointing at
     * an address we will never check.
     *
     * @return array{broken: bool, reason: string}
     */
    public function check(string $url, int $timeout): array
    {
        $current = $url;

        for ($hop = 0; $hop <= OutboundUrlGuard::MAX_HOPS; $hop++) {
            $target = $this->guard->resolve($current);

            if (! $target['ok']) {
                return ['broken' => true, 'reason' => $target['reason']];
            }

            $response = $this->send('head', $current, $target, $timeout);

            if (in_array($response->status(), [405, 501], true)) {
                // Some shops reject HEAD outright.
                $response = $this->send('get', $current, $target, $timeout);
            }

            $status = $response->status();

            if ($status >= 300 && $status < 400) {
                $location = (string) $response->header('Location');

                if (trim($location) === '') {
                    return ['broken' => true, 'reason' => 'redirection sans cible'];
                }

                $current = $this->absolute($location, $current);

                continue;
            }

            return [
                'broken' => $status >= 400,
                'reason' => $status >= 400 ? 'statut '.$status : '',
            ];
        }

        return ['broken' => true, 'reason' => 'trop de redirections'];
    }

    /**
     * Send one request to an already vetted target.
     *
     * @param  array{ok: bool, reason: string, host: string, ip: string, port: int}  $target
     */
    private function send(string $method, string $url, array $target, int $timeout): Response
    {
        $maxBytes = (int) config('dolinews.links.check_max_bytes', 262144);

        return Http::timeout($timeout)
            ->connectTimeout(min($timeout, 5))
            ->withHeaders(['User-Agent' => 'DoliNews-LinkCheck/1.0'])
            ->withOptions([
                // Hops are re-vetted above, so the client must not take
                // any on its own.
                'allow_redirects' => false,
                'curl' => [
                    // Pin the address the guard just vetted: the name
                    // cannot resolve elsewhere between check and call.
                    CURLOPT_RESOLVE => [
                        $target['host'].':'.$target['port'].':'.$target['ip'],
                    ],
                    // Only meaningful when the peer declares a length,
                    // which is why the timeout stays the real ceiling.
                    CURLOPT_MAXFILESIZE => $maxBytes,
                ],
            ])
            ->{$method}($url);
    }

    /**
     * Resolve a Location header against the URL it came from.
     */
    private function absolute(string $location, string $base): string
    {
        $location = trim($location);

        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $scheme = (string) (parse_url($base, PHP_URL_SCHEME) ?: 'https');
        $host = (string) (parse_url($base, PHP_URL_HOST) ?: '');
        $port = parse_url($base, PHP_URL_PORT);
        $authority = $host.(is_int($port) ? ':'.$port : '');

        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $scheme.'://'.$authority.$location;
        }

        $path = (string) (parse_url($base, PHP_URL_PATH) ?: '/');
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $scheme.'://'.$authority.$directory.$location;
    }
}

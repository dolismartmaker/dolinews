<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures every request carries a stable X-Request-Id.
 *
 * A client-supplied id is propagated when it looks like an id, and
 * regenerated otherwise: the value lands in the logs and in the
 * response, so an arbitrarily long or arbitrarily shaped string is
 * something a stranger writes into our journal. Symfony already refuses
 * line breaks, so this is about keeping the logs readable, not about
 * header injection.
 */
class RequestIdMiddleware
{
    public const HEADER = 'X-Request-Id';

    /** Longest client-supplied id accepted, a UUID being 36. */
    private const MAX_LENGTH = 64;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->headers->get(self::HEADER);

        if (! is_string($requestId) || ! $this->isAcceptable(trim($requestId))) {
            $requestId = (string) Str::uuid();
        } else {
            $requestId = trim($requestId);
        }

        $request->headers->set(self::HEADER, $requestId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    /**
     * Whether a client-supplied id may be carried as is: short, and made
     * of the characters a correlation id is made of.
     */
    private function isAcceptable(string $requestId): bool
    {
        if ($requestId === '' || strlen($requestId) > self::MAX_LENGTH) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9._-]+$/', $requestId) === 1;
    }
}

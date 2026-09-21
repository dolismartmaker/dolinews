<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets a conservative Cache-Control policy on API responses (S7).
 *
 * Authenticated API payloads are tenant-specific, so by default they must
 * not be stored by shared caches. Endpoints that want caching can set their
 * own Cache-Control header upstream; this middleware only fills the gap when
 * none was provided.
 */
class CacheHeadersMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! $response->headers->has('Cache-Control')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}

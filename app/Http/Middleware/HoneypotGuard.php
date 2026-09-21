<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\HoneypotReporter;
use App\Support\HoneypotMatcher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Honeypot branch: append on the GLOBAL pile, after TrustProxies
 * (LARAVEL_HONEYPOT.md).
 *
 * 404 and not 418: the code of the teapot was the marker the historic
 * implementation hooked on; the explicit marker does that job now, and
 * a 404 tells the scanner nothing the server layer would not.
 */
class HoneypotGuard
{
    public function __construct(private readonly HoneypotReporter $reporter) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('honeypot.enabled', true)) {
            return $next($request);
        }

        $hit = HoneypotMatcher::match($request->path());

        if ($hit === null) {
            return $next($request);
        }

        $this->reporter->report($request, $hit);

        abort(404);
    }
}

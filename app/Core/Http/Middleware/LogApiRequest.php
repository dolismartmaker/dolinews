<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Models\ApiRequest;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records each authenticated API call into api_requests (S7).
 *
 * Runs as terminable middleware so the persistence happens after the
 * response has been sent and never adds latency to the client.
 */
class LogApiRequest
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Persist the call metadata after the response is delivered.
     */
    public function terminate(Request $request, Response $response): void
    {
        // Tokened API calls authenticate on the sanctum guard, which does
        // not become the request's default user resolver: ask for it
        // explicitly, then fall back to the web guard.
        $user = $request->user('sanctum') ?? $request->user();

        if (! $user instanceof User) {
            // Public endpoints are not logged as quota-bearing calls.
            return;
        }

        try {
            ApiRequest::query()->create([
                'user_id' => $user->getKey(),
                'method' => $request->getMethod(),
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
            ]);
        } catch (Throwable $e) {
            // Logging a call must never break the request lifecycle.
            Log::error('LogApiRequest: failed to persist api request', [
                'user_id' => $user->getKey(),
                'path' => $request->path(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}

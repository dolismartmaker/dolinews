<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Enums\ApiErrorCode;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the API surface (S7).
 *
 * Requires a valid Sanctum-authenticated user and an active account.
 * On failure it returns the JSON error envelope, never a redirect.
 */
class AuthenticateApi
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            Log::warning('AuthenticateApi: missing or invalid Sanctum token', [
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            return $this->deny(ApiErrorCode::INVALID_TOKEN);
        }

        if (! $user->active) {
            Log::warning('AuthenticateApi: account is inactive', [
                'user_id' => $user->getKey(),
                'path' => $request->path(),
            ]);

            return $this->deny(ApiErrorCode::ACCOUNT_INACTIVE);
        }

        return $next($request);
    }

    /**
     * Build a JSON error envelope for a denied request.
     */
    private function deny(ApiErrorCode $code): JsonResponse
    {
        return response()->json([
            'error' => $code->value,
            'message' => $code->message(),
        ], $code->httpStatus());
    }
}

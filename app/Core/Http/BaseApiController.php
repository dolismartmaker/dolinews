<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Enums\ApiErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Base controller enforcing the uniform JSON envelope contract (S8).
 *
 * Success responses are shaped as { "data": ..., "meta": ... }.
 * Error responses are shaped as { "error": "CODE", "message": "..." }.
 */
abstract class BaseApiController extends Controller
{
    /**
     * Return a 200 success envelope.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function ok(mixed $data = null, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => (object) $meta,
        ], 200);
    }

    /**
     * Return a 201 success envelope.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function created(mixed $data = null, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => (object) $meta,
        ], 201);
    }

    /**
     * Return an error envelope with the HTTP status mapped by the error code.
     *
     * @param  array<string, mixed>  $detail
     */
    protected function error(ApiErrorCode $code, array $detail = []): JsonResponse
    {
        $payload = [
            'error' => $code->value,
            'message' => $code->message(),
        ];

        if ($detail !== []) {
            $payload['detail'] = $detail;
        }

        return response()->json($payload, $code->httpStatus());
    }
}

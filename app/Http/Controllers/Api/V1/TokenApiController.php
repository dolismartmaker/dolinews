<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Core\Audit\AuditLogger;
use App\Core\Enums\ApiErrorCode;
use App\Core\Http\BaseApiController;
use App\Http\Controllers\Concerns\ResolvesUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Personal API token lifecycle over the API itself (SPEC 5.2): a
 * first token is minted from the web account page, then this surface
 * lets an integration chain rotate its own tokens.
 */
class TokenApiController extends BaseApiController
{
    use ResolvesUser;

    /**
     * GET /api/v1/tokens : names and metadata, never the secrets.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        return $this->ok($user->tokens()
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn ($token): array => [
                'id' => $token->getKey(),
                'name' => $token->name,
                'created_at' => $token->created_at?->format('Y-m-d H:i:s'),
                'last_used_at' => $token->last_used_at?->format('Y-m-d H:i:s'),
            ])
            ->all());
    }

    /**
     * POST /api/v1/tokens : mint one. The plaintext appears exactly
     * once, in this response.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $token = $user->createToken((string) $payload['name']);

        app(AuditLogger::class)->log('token.created', $user, ['name' => (string) $payload['name'], 'via' => 'api']);

        return $this->created([
            'name' => (string) $payload['name'],
            'token' => $token->plainTextToken,
        ]);
    }

    /**
     * DELETE /api/v1/tokens/{id}.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $this->requireUser($request);

        $deleted = $user->tokens()->where('id', $id)->delete();

        if ($deleted === 0) {
            return $this->error(ApiErrorCode::NOT_FOUND);
        }

        app(AuditLogger::class)->log('token.revoked', $user, ['token_id' => $id, 'via' => 'api']);

        return $this->ok(['revoked' => true]);
    }
}

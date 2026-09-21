<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Core\Http\BaseApiController;
use App\Http\Controllers\Concerns\ResolvesUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Account surface of the public API (SPEC 5.2): profile and token
 * lifecycle.
 */
class AccountApiController extends BaseApiController
{
    use ResolvesUser;

    /**
     * GET /api/v1/profile.
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        return $this->ok([
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'display_name' => $user->display_name,
            'bio' => $user->bio,
            'website' => $user->website,
            'is_contributor' => $user->isContributor(),
            'email_verified_at' => $user->email_verified_at?->format('Y-m-d H:i:s'),
            // The pivot row is read through an explicit join: the
            // belongsToMany collection elements carry no pivot type.
            'editors' => DB::table('editor_user')
                ->join('editors', 'editors.id', '=', 'editor_user.editor_id')
                ->where('editor_user.user_id', $user->getKey())
                ->orderBy('editors.name')
                ->get(['editors.id', 'editors.slug', 'editors.name', 'editor_user.role'])
                ->map(static fn (\stdClass $row): array => [
                    'id' => (int) $row->id,
                    'slug' => (string) $row->slug,
                    'name' => (string) $row->name,
                    'role' => (string) $row->role,
                ])
                ->all(),
        ]);
    }

    /**
     * POST /api/v1/logout : revoke the token that made the call.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        // The call always sits behind auth:sanctum: a token is
        // present, its deletion is unconditional.
        $user->currentAccessToken()->delete();

        return $this->ok(['revoked' => true]);
    }
}

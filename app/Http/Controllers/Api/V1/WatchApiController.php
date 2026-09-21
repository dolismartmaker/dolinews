<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Core\Enums\ApiErrorCode;
use App\Core\Http\BaseApiController;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Subscriptions\WatchService;
use App\Http\Controllers\Concerns\ResolvesUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reader subscriptions over the API (SPEC 5.2/6.4): project watches,
 * editor watches, and the personal feed token.
 */
class WatchApiController extends BaseApiController
{
    use ResolvesUser;

    private const FOCUS_VALUES = 'doc,cleanup,feature_minor,feature_major,bugfix_minor,bugfix_major,security,compat,eol';

    private const MATURITY_VALUES = 'alpha,beta,rc,stable,deprecated';

    public function __construct(
        private readonly WatchService $watches,
    ) {}

    /**
     * GET /api/v1/watches : the account's watches.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        return $this->ok([
            'projects' => $user->projectWatches()->with('project')->get()
                ->map(static fn ($watch): array => [
                    'project' => $watch->project?->only(['id', 'slug', 'name']),
                    'focus_filter' => $watch->focus_filter,
                    'maturity_filter' => $watch->maturity_filter,
                ])->all(),
            'editors' => $user->editorWatches()->with('editor')->get()
                ->map(static fn ($watch): array => [
                    'editor' => $watch->editor?->only(['id', 'slug', 'name']),
                    'focus_filter' => $watch->focus_filter,
                    'maturity_filter' => $watch->maturity_filter,
                ])->all(),
            'feed_url' => $user->feed_token !== null
                ? route('feeds.personal', ['token' => $user->feed_token])
                : null,
        ]);
    }

    /**
     * POST /api/v1/watches : add or remove a watch.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        $payload = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'editor_id' => ['nullable', 'integer', 'exists:editors,id'],
            'focus' => ['nullable', 'array'],
            'focus.*' => ['in:'.self::FOCUS_VALUES],
            'maturity' => ['nullable', 'array'],
            'maturity.*' => ['in:'.self::MATURITY_VALUES],
        ]);

        if (empty($payload['project_id']) && empty($payload['editor_id'])) {
            return $this->error(ApiErrorCode::VALIDATION_FAILED, [
                'project_id' => 'project_id ou editor_id est requis.',
            ]);
        }

        $filters = [
            'focus' => array_values((array) ($payload['focus'] ?? [])) ?: null,
            'maturities' => array_values((array) ($payload['maturity'] ?? [])) ?: null,
        ];

        $added = isset($payload['project_id'])
            ? $this->watches->toggleProject(
                $user,
                Project::query()->findOrFail((int) $payload['project_id']),
                $filters,
            )
            : $this->watches->toggleEditor(
                $user,
                Editor::query()->findOrFail((int) $payload['editor_id']),
                $filters,
            );

        return $this->ok(['watching' => $added]);
    }

    /**
     * POST /api/v1/watches/feed-token : issue or regenerate the personal
     * feed token (SPEC 6.4). Regenerating revokes the previous URL.
     */
    public function feedToken(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        $token = $request->boolean('regenerate') && $user->feed_token !== null
            ? $this->watches->regenerateFeedToken($user)
            : $this->watches->issueFeedToken($user);

        return $this->ok([
            'feed_url' => route('feeds.personal', ['token' => $token]),
        ]);
    }
}

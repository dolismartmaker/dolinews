<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Core\Enums\ApiErrorCode;
use App\Core\Http\BaseApiController;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Http\Controllers\Concerns\ResolvesUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Editor directory over the public API (SPEC 5.2).
 */
class EditorApiController extends BaseApiController
{
    use ResolvesUser;

    /**
     * GET /api/v1/editors.
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = Editor::query()
            ->orderBy('name')
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        return $this->ok(
            $paginator->getCollection()
                ->map(fn (Editor $editor): array => $this->editorPayload($editor))
                ->all(),
            [
                'current_page' => $paginator->currentPage(),
                'total' => $paginator->total(),
            ],
        );
    }

    /**
     * GET /api/v1/editors/{slug}.
     */
    public function show(string $slug): JsonResponse
    {
        /** @var Editor|null $editor */
        $editor = Editor::query()->where('slug', $slug)->first();

        if ($editor === null) {
            return $this->error(ApiErrorCode::NOT_FOUND);
        }

        return $this->ok($this->editorPayload($editor, detailed: true));
    }

    /**
     * @return array<string, mixed>
     */
    private function editorPayload(Editor $editor, bool $detailed = false): array
    {
        $payload = [
            'id' => $editor->getKey(),
            'slug' => $editor->slug,
            'name' => $editor->name,
            'verified' => $editor->verified_at !== null,
        ];

        if ($detailed) {
            $payload['description'] = $editor->description;
            $payload['website'] = $editor->website;
            $payload['verified_at'] = $editor->verified_at?->format('Y-m-d H:i:s');
            $payload['projects'] = $editor->projects()
                ->get(['id', 'slug', 'name'])
                ->map(static fn (Project $project): array => [
                    'id' => $project->getKey(),
                    'slug' => $project->slug,
                    'name' => $project->name,
                ])
                ->all();
        }

        return $payload;
    }
}

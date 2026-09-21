<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Core\Enums\ApiErrorCode;
use App\Core\Http\BaseApiController;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Enums\LinkType;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Projects\ProjectException;
use App\Domain\Dolinews\Projects\ProjectService;
use App\Http\Controllers\Concerns\ResolvesUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Project sheets over the public API (SPEC 5.2): reading for everyone,
 * creation and translations for verified contributors.
 */
class ProjectApiController extends BaseApiController
{
    use ResolvesUser;

    public function __construct(
        private readonly ProjectService $projects,
        private readonly EditorService $editors,
    ) {}

    /**
     * GET /api/v1/projects.
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = Project::query()
            ->with('editor')
            ->orderBy('name')
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        return $this->ok(
            $paginator->getCollection()
                ->map(fn (Project $project): array => $this->projectPayload($project))
                ->all(),
            [
                'current_page' => $paginator->currentPage(),
                'total' => $paginator->total(),
            ],
        );
    }

    /**
     * GET /api/v1/projects/{slug}.
     */
    public function show(string $slug): JsonResponse
    {
        /** @var Project|null $project */
        $project = Project::query()->with('editor', 'links', 'translations')->where('slug', $slug)->first();

        if ($project === null) {
            return $this->error(ApiErrorCode::NOT_FOUND);
        }

        return $this->ok($this->projectPayload($project, detailed: true));
    }

    /**
     * POST /api/v1/projects : create a sheet (SPEC 4.2). The sheet
     * carries no compatibility: anything dated lives in the feed.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        if (! $user->isContributor()) {
            return $this->error(ApiErrorCode::CONTRIBUTOR_REQUIRED);
        }

        $payload = $request->validate([
            'editor_id' => ['required', 'integer', 'exists:editors,id'],
            'name' => ['required', 'string', 'max:150'],
            'summary' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'license' => ['nullable', 'string', 'max:50'],
        ]);

        /** @var Editor|null $editor */
        $editor = Editor::query()->find((int) $payload['editor_id']);

        if ($editor === null || ! $this->editors->isMember($editor, $user)) {
            return $this->error(ApiErrorCode::FORBIDDEN, [
                'editor_id' => 'Editeur inconnu ou non membre.',
            ]);
        }

        $project = $this->projects->create($editor, $payload);

        return $this->created($this->projectPayload($project, detailed: true));
    }

    /**
     * POST /api/v1/projects/{slug}/links : add a typed link (SPEC 8).
     */
    public function storeLink(Request $request, string $slug): JsonResponse
    {
        $user = $this->requireUser($request);

        /** @var Project|null $project */
        $project = Project::query()->where('slug', $slug)->first();

        if ($project === null) {
            return $this->error(ApiErrorCode::NOT_FOUND);
        }

        $editor = $project->editor;

        if ($editor === null || ! $this->editors->isMember($editor, $user)) {
            return $this->error(ApiErrorCode::FORBIDDEN);
        }

        $payload = $request->validate([
            'type' => ['required', 'in:dolistore,shop,demo,doc,repo,support,other'],
            'url' => ['required', 'url:http,https', 'max:2048'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $link = $this->projects->addLink(
                $project,
                LinkType::from((string) $payload['type']),
                (string) $payload['url'],
                $payload['label'] ?? null,
            );
        } catch (ProjectException $e) {
            // A claimed (type, external_id) is a DETECTION: the conflict
            // is surfaced for human resolution (SPEC 9.5).
            return $this->error(ApiErrorCode::CONFLICT, ['reason' => $e->getMessage()]);
        }

        return $this->created([
            'id' => $link->getKey(),
            'type' => $link->type->value,
            'url' => $link->url,
            'external_id' => $link->external_id,
        ]);
    }

    /**
     * POST /api/v1/projects/{slug}/translations : translate a sheet
     * (SPEC D14, 5.2).
     */
    public function storeTranslation(Request $request, string $slug): JsonResponse
    {
        $user = $this->requireUser($request);

        if (! $user->isContributor()) {
            return $this->error(ApiErrorCode::CONTRIBUTOR_REQUIRED);
        }

        /** @var Project|null $project */
        $project = Project::query()->where('slug', $slug)->first();

        if ($project === null) {
            return $this->error(ApiErrorCode::NOT_FOUND);
        }

        $editor = $project->editor;

        if ($editor === null || ! $this->editors->isMember($editor, $user)) {
            return $this->error(ApiErrorCode::FORBIDDEN);
        }

        $payload = $request->validate([
            'locale' => ['required', 'string', 'size:5'],
            'name' => ['required', 'string', 'max:150'],
            'summary' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $translation = $this->projects->translate(
            $project,
            (string) $payload['locale'],
            $payload,
        );

        return $this->created([
            'id' => $translation->getKey(),
            'locale' => $translation->locale,
        ]);
    }

    /**
     * Wire shape of a sheet.
     *
     * @return array<string, mixed>
     */
    private function projectPayload(Project $project, bool $detailed = false): array
    {
        $payload = [
            'id' => $project->getKey(),
            'slug' => $project->slug,
            'name' => $project->name,
            'summary' => $project->summary,
            'status' => $project->status->value,
            'editor' => $project->editor?->only(['id', 'slug', 'name']),
        ];

        if ($detailed) {
            $payload['description'] = $project->description;
            $payload['license'] = $project->license;
            $payload['links'] = $project->links->map(static fn ($link): array => [
                'type' => $link->type->value,
                'url' => $link->url,
                'label' => $link->label,
                'is_broken' => $link->is_broken,
            ])->all();
            $payload['translations'] = $project->translations->map(static fn ($translation): array => [
                'locale' => $translation->locale,
                'name' => $translation->name,
            ])->all();
        }

        return $payload;
    }
}

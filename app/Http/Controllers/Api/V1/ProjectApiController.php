<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Core\Enums\ApiErrorCode;
use App\Core\Http\BaseApiController;
use App\Domain\Dolinews\Articles\TranslationMandateService;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Enums\LinkType;
use App\Domain\Dolinews\Markdown\ArticleMarkdown;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Media;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\ProjectMedia;
use App\Domain\Dolinews\Projects\ProjectException;
use App\Domain\Dolinews\Projects\ProjectService;
use App\Domain\Dolinews\Projects\SheetRules;
use App\Http\Controllers\Concerns\ResolvesUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Project sheets over the public API (SPEC 5.2): reading for everyone,
 * creation and translations for verified contributors, logo and gallery
 * for the members of the sheet's editor.
 */
class ProjectApiController extends BaseApiController
{
    use ResolvesUser;

    public function __construct(
        private readonly ProjectService $projects,
        private readonly EditorService $editors,
        private readonly TranslationMandateService $mandates,
        private readonly ArticleMarkdown $markdown,
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
        $project = Project::query()
            ->with('editor', 'links', 'translations', 'logo', 'gallery.media')
            ->where('slug', $slug)
            ->first();

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

        $payload = $request->validate(array_merge(
            ['editor_id' => ['required', 'integer', 'exists:editors,id']],
            SheetRules::reference(),
        ));

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
     * PATCH /api/v1/projects/{slug} : rewrite the reference fields of a
     * sheet (SPEC 4.2).
     *
     * Added because a sheet could be created by the API and never
     * corrected by it: a catalogue deposited by a tool was stuck with
     * whatever its first run wrote, and a description worth reading is
     * rarely written on the first pass. Only the fields present in the
     * call are touched, so a tool fixing the description alone cannot
     * blank the licence it did not send.
     *
     * The links, the translations and the status have their own
     * endpoints and screens: this one writes the text.
     */
    public function update(Request $request, string $slug): JsonResponse
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

        $payload = $request->validate(SheetRules::reference(partial: true));

        if ($payload === []) {
            return $this->error(ApiErrorCode::VALIDATION_FAILED, [
                'payload' => 'Aucun champ à modifier.',
            ]);
        }

        $this->projects->update($project, $payload);

        return $this->ok($this->projectPayload($project->refresh(), detailed: true));
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
     * PUT /api/v1/projects/{slug}/logo : set the logo of a sheet, or clear
     * it with a null media_id (SPEC 4.2).
     */
    public function updateLogo(Request $request, string $slug): JsonResponse
    {
        $project = $this->editableProject($request, $slug);

        if ($project instanceof JsonResponse) {
            return $project;
        }

        $payload = $request->validate([
            'media_id' => ['present', 'nullable', 'integer', 'exists:media,id'],
        ]);

        $media = $payload['media_id'] === null ? null : Media::query()->find((int) $payload['media_id']);

        try {
            $this->projects->setLogo($project, $media);
        } catch (ProjectException $e) {
            return $this->error(ApiErrorCode::FORBIDDEN, ['media_id' => $e->getMessage()]);
        }

        return $this->ok($this->projectPayload($project->refresh()->load('editor', 'links', 'translations', 'logo', 'gallery.media'), detailed: true));
    }

    /**
     * POST /api/v1/projects/{slug}/gallery : add an image to the gallery
     * of a sheet, or update the caption and place of one already in it
     * (SPEC 4.2/4.4).
     */
    public function storeGalleryImage(Request $request, string $slug): JsonResponse
    {
        $project = $this->editableProject($request, $slug);

        if ($project instanceof JsonResponse) {
            return $project;
        }

        $payload = $request->validate([
            'media_id' => ['required', 'integer', 'exists:media,id'],
            'caption' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        /** @var Media $media */
        $media = Media::query()->findOrFail((int) $payload['media_id']);
        $isNew = ! $project->gallery()->where('media_id', $media->getKey())->exists();

        try {
            $entry = $this->projects->addToGallery(
                $project,
                $media,
                $payload['caption'] ?? null,
                isset($payload['position']) ? (int) $payload['position'] : null,
            );
        } catch (ProjectException $e) {
            if ($e->getCode() === ProjectException::GALLERY_FULL) {
                return $this->error(ApiErrorCode::VALIDATION_FAILED, [
                    'media_id' => $e->getMessage(),
                    'gallery_max' => (int) config('dolinews.projects.gallery_max'),
                ]);
            }

            return $this->error(ApiErrorCode::FORBIDDEN, ['media_id' => $e->getMessage()]);
        }

        $entry->setRelation('media', $media);
        $data = $this->galleryEntryPayload($entry);

        return $isNew ? $this->created($data) : $this->ok($data);
    }

    /**
     * DELETE /api/v1/projects/{slug}/gallery/{mediaId} : take an image out
     * of the gallery. The file stays until the purge finds nothing
     * holding it.
     */
    public function destroyGalleryImage(Request $request, string $slug, int $mediaId): JsonResponse
    {
        $project = $this->editableProject($request, $slug);

        if ($project instanceof JsonResponse) {
            return $project;
        }

        if (! $this->projects->removeFromGallery($project, $mediaId)) {
            return $this->error(ApiErrorCode::NOT_FOUND);
        }

        return $this->ok(['media_id' => $mediaId, 'removed' => true]);
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

        if ($editor === null) {
            return $this->error(ApiErrorCode::FORBIDDEN);
        }

        $payload = $request->validate(SheetRules::translation());

        // A member of the editor, or the translator it mandated for this
        // sheet and this language (SPEC 5.6): a sheet is one of the two
        // things a mandate was made to cover, and refusing it here left
        // the delegation unable to do the work it names.
        $mandated = $this->mandates->covers(
            $editor,
            $user,
            $project->getKey(),
            (string) $payload['locale'],
        );

        if (! $this->editors->isMember($editor, $user) && ! $mandated) {
            return $this->error(ApiErrorCode::FORBIDDEN);
        }

        $translation = $this->projects->translate(
            $project,
            (string) $payload['locale'],
            $payload + ['auto_translated' => false, 'source_fingerprint' => null],
        );

        return $this->created([
            'id' => $translation->getKey(),
            'locale' => $translation->locale,
        ]);
    }

    /**
     * The sheet behind a write on its media, or the refusal to answer:
     * same rule as the links, a member of the sheet's editor only.
     */
    private function editableProject(Request $request, string $slug): Project|JsonResponse
    {
        $user = $this->requireUser($request);

        /** @var Project|null $project */
        $project = Project::query()->where('slug', $slug)->first();

        if ($project === null) {
            Log::info('ProjectApiController: sheet not found', ['slug' => $slug]);

            return $this->error(ApiErrorCode::NOT_FOUND);
        }

        $editor = $project->editor;

        if ($editor === null || ! $this->editors->isMember($editor, $user)) {
            Log::warning('ProjectApiController: sheet media write by a non member', [
                'project' => $project->getKey(),
                'user' => $user->getKey(),
            ]);

            return $this->error(ApiErrorCode::FORBIDDEN);
        }

        return $project;
    }

    /**
     * Wire shape of one gallery image.
     *
     * @return array<string, mixed>
     */
    private function galleryEntryPayload(ProjectMedia $entry): array
    {
        return $this->mediaPayload($entry->media) + [
            'caption' => $entry->caption,
            'position' => $entry->position,
        ];
    }

    /**
     * Wire shape of a medium shown on a sheet. source_hash is what lets a
     * client tell a file already deposited from a new one without
     * uploading it (SPEC 4.4).
     *
     * @return array<string, mixed>
     */
    private function mediaPayload(?Media $media): array
    {
        if ($media === null) {
            return [];
        }

        return [
            'media_id' => $media->getKey(),
            'url' => $media->url(),
            'alt' => $media->alt,
            'width' => $media->width,
            'height' => $media->height,
            'source_hash' => $media->source_hash,
        ];
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
            'locale' => $project->locale,
            'summary' => $project->summary,
            'status' => $project->status->value,
            'editor' => $project->editor?->only(['id', 'slug', 'name']),
        ];

        if ($detailed) {
            $payload['description'] = $project->description;
            // Rendered here like body_html on an announcement: a client
            // showing a sheet should not have to run a Markdown renderer
            // of its own, nor guess which tags this service allows.
            $payload['description_html'] = $project->description === null
                ? null
                : $this->markdown->render($project->description);
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
            // What is already on the sheet, so a tool sends only what is
            // missing (SPEC 4.2/4.4).
            $payload['logo'] = $project->logo === null ? null : $this->mediaPayload($project->logo);
            $payload['gallery'] = $project->gallery
                ->filter(static fn (ProjectMedia $entry): bool => $entry->media !== null)
                ->map(fn (ProjectMedia $entry): array => $this->galleryEntryPayload($entry))
                ->values()
                ->all();
        }

        return $payload;
    }
}

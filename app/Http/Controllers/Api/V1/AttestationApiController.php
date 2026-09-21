<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Core\Enums\ApiErrorCode;
use App\Core\Http\BaseApiController;
use App\Domain\Dolinews\Attestations\AttestationService;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Project;
use App\Http\Controllers\Concerns\ResolvesUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Test-attestation ingestion (SPEC 10): editors push the indicators of
 * their self-hosted capTests instances for their own sheets.
 *
 * The indicator stays declarative while the instances are hosted by the
 * editors: the API trusts its authenticated caller, that is the whole
 * trust boundary, and the public vocabulary never says "certified".
 */
class AttestationApiController extends BaseApiController
{
    use ResolvesUser;

    public function __construct(
        private readonly AttestationService $attestations,
        private readonly EditorService $editors,
    ) {}

    /**
     * POST /api/v1/attestations.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        if (! $user->isContributor()) {
            return $this->error(ApiErrorCode::CONTRIBUTOR_REQUIRED);
        }

        $payload = $request->validate([
            'project' => ['required', 'string', 'exists:projects,slug'],
            'article_id' => ['nullable', 'integer', 'exists:articles,id'],
            'source_type' => ['required', 'in:captests,ci,other'],
            'source_url' => ['required', 'url:http,https', 'max:2048'],
            'metric' => ['required', 'string', 'max:50'],
            'value' => ['required', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:20'],
            'measured_at' => ['nullable', 'date', 'before_or_equal:now'],
            'signature' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var Project|null $project */
        $project = Project::query()->where('slug', (string) $payload['project'])->first();

        if ($project === null) {
            return $this->error(ApiErrorCode::NOT_FOUND);
        }

        $editor = $project->editor;

        if ($editor === null || ! $this->editors->isMember($editor, $user)) {
            return $this->error(ApiErrorCode::FORBIDDEN);
        }

        // exists:articles,id only says the article is real, not that it
        // belongs to this project: without this the sheet would display
        // an attestation bound to an announcement of somebody else's.
        if (isset($payload['article_id'])) {
            $belongs = Article::query()
                ->whereKey((int) $payload['article_id'])
                ->where('project_id', $project->getKey())
                ->exists();

            if (! $belongs) {
                return $this->error(ApiErrorCode::VALIDATION_FAILED, [
                    'article_id' => ['Cet article n\'appartient pas au projet visé.'],
                ]);
            }
        }

        $attestation = $this->attestations->record($project, $payload);

        return $this->created([
            'id' => $attestation->getKey(),
            // The label is part of the contract (SPEC 10): "tests
            // publiés par l'éditeur", never "certifié" nor "qualité".
            'display_label' => 'tests publiés par l\'éditeur',
            'received_at' => $attestation->received_at->format('Y-m-d H:i:s'),
        ]);
    }
}

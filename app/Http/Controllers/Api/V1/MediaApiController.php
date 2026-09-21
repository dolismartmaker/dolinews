<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Core\Enums\ApiErrorCode;
use App\Core\Http\BaseApiController;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Media\MediaException;
use App\Domain\Dolinews\Media\MediaService;
use App\Domain\Dolinews\Models\Editor;
use App\Http\Controllers\Concerns\ResolvesUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Media deposit endpoint (SPEC 5.2, step one of the two-step
 * illustrated publication, and SPEC 7 for the intake rules).
 */
class MediaApiController extends BaseApiController
{
    use ResolvesUser;

    public function __construct(
        private readonly MediaService $media,
        private readonly EditorService $editors,
    ) {}

    /**
     * POST /api/v1/media : upload one image, get its identifier back.
     *
     * The file is re-encoded no matter what the client says it is;
     * SVG is refused; EXIF drops out of the rewrite.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        if (! $user->isContributor()) {
            return $this->error(ApiErrorCode::CONTRIBUTOR_REQUIRED);
        }

        $payload = $request->validate([
            'file' => ['required', 'file'],
            'editor_id' => ['required', 'integer', 'exists:editors,id'],
            'alt' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var Editor|null $editor */
        $editor = Editor::query()->find((int) $payload['editor_id']);

        if ($editor === null || ! $this->editors->isMember($editor, $user)) {
            return $this->error(ApiErrorCode::FORBIDDEN, [
                'editor_id' => 'Editeur inconnu ou non membre.',
            ]);
        }

        $maxBytes = (int) config('dolinews.media.max_bytes');

        if ($request->file('file') === null || $request->file('file')->getError() === UPLOAD_ERR_INI_SIZE) {
            return $this->error(ApiErrorCode::PAYLOAD_TOO_LARGE);
        }

        try {
            $media = $this->media->store(
                $request->file('file'),
                $editor,
                $payload['alt'] ?? null,
            );
        } catch (MediaException $e) {
            $code = str_contains($e->getMessage(), 'volumineux')
                ? ApiErrorCode::PAYLOAD_TOO_LARGE
                : ApiErrorCode::UNSUPPORTED_MEDIA;

            return $this->error($code, ['reason' => $e->getMessage()]);
        }

        return $this->created([
            'id' => $media->getKey(),
            'url' => $media->url(),
            'mime' => $media->mime,
            'width' => $media->width,
            'height' => $media->height,
            'bytes' => $media->bytes,
            // The warning is part of the contract (SPEC 7): screenshots
            // of Dolibarr routinely carry real personal data.
            'warning' => 'Une capture de Dolibarr contient souvent des donnees reelles '
                .'(tiers, montants, adresses) : nettoyez-la avant envoi.',
        ]);
    }
}

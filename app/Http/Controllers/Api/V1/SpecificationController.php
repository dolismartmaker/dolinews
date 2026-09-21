<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Dolinews\Api\OpenApiSpec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Serves the OpenAPI document of the public API (SPEC 5.2).
 *
 * The one endpoint that does not answer in the {data, meta} envelope:
 * a client generator expects the specification itself, and wrapping it
 * would make the document unusable by the tools it exists for.
 */
class SpecificationController extends Controller
{
    public function __construct(
        private readonly OpenApiSpec $spec,
    ) {}

    /**
     * GET /api/v1/openapi.json.
     */
    public function show(Request $request): JsonResponse
    {
        $document = $this->spec->document(url('/api/v1'));

        $response = response()->json($document, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // The document only changes when the service is deployed: a
        // validator spares the round trip to a generator that polls it.
        $response->setEtag(md5((string) $response->getContent()));
        $response->setPublic();
        $response->isNotModified($request);

        return $response;
    }
}

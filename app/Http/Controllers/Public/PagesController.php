<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Dolinews\Api\OpenApiSpec;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * Static public pages: the versioned commitments (SPEC 12), the
 * numbered usage rules (SPEC 9.2), the personal-data summary
 * (SPEC 9.8) and the API documentation (SPEC 5.2). The first three are
 * launch conditions: published and versioned before the service opens.
 */
class PagesController extends Controller
{
    /**
     * The public commitments, versioned (SPEC 12).
     */
    public function commitments(): View
    {
        return view('public.commitments', [
            'version' => config('dolinews.commitments_version', '1.0'),
        ]);
    }

    /**
     * The numbered usage rules, versioned (SPEC 9.2): a sanction can
     * only rely on a numbered rule that existed at the time of the
     * facts, never retroactively.
     */
    public function rules(): View
    {
        return view('public.rules', [
            'version' => config('dolinews.rules_version', '1.0'),
        ]);
    }

    /**
     * Personal data summary (SPEC 9.8): inventory and rights.
     */
    public function data(): View
    {
        return view('public.data');
    }

    /**
     * Legal notices.
     */
    public function legal(): View
    {
        return view('public.legal');
    }

    /**
     * The editor's path, from account to first published article
     * (SPEC 3, 5): the qualification, the sheet, the token and the
     * review circuit told in order.
     *
     * The base URL comes from the instance rather than the text: a
     * self-hosted deployment shows its own endpoints.
     */
    public function editorGuide(): View
    {
        return view('public.editor-guide', [
            'baseUrl' => url('/api/v1'),
        ]);
    }

    /**
     * Documentation of the public API (SPEC 5.2), rendered from the
     * OpenAPI document that /api/v1/openapi.json serves.
     *
     * Rendered server-side on purpose: a public page of this service
     * loads one stylesheet and no JavaScript, which rules out the usual
     * specification viewers. The reader gets the same contract, the
     * page keeps working without scripts.
     */
    public function apiDocumentation(OpenApiSpec $spec): View
    {
        return view('public.api', [
            'info' => $spec->info(),
            'version' => $spec->version(),
            'baseUrl' => url('/api/v1'),
            'groups' => $spec->operationsByTag(),
            'tokenNotice' => $spec->securityDescription(),
            'throttles' => $spec->throttles(),
            'errorCodes' => $spec->errorCodes(),
        ]);
    }

    /**
     * Crawler instructions, served by the application rather than from
     * a file: the sitemap has to be named by its absolute address, and
     * a self-hosted deployment does not run on the same domain.
     *
     * What is refused here is refused because it is a credential or a
     * form, never because it is content: a tokenised personal feed and
     * an unsubscribe link are authorisations in an URL (SPEC 6.4), and
     * the report forms hold nothing of their own (SPEC 9.9). Reading
     * remains free and accountless, which is what the map opens wide.
     */
    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /feeds/',
            'Disallow: /desabonnement/',
            'Disallow: /signaler/',
            'Disallow: /account',
            'Disallow: /admin',
            '',
            'Sitemap: '.route('sitemap.index'),
            '',
        ];

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}

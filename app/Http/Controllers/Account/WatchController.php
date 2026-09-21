<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Subscriptions\WatchService;
use App\Http\Controllers\Concerns\ResolvesUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Watch toggles from the public pages and the account page
 * (SPEC 6.4): a watch may carry its own focus and maturity filters.
 */
class WatchController extends Controller
{
    use ResolvesUser;

    private const FOCUS_VALUES = 'doc,cleanup,feature_minor,feature_major,bugfix_minor,bugfix_major,security,compat,eol';

    private const MATURITY_VALUES = 'alpha,beta,rc,stable,deprecated';

    public function __construct(
        private readonly WatchService $watches,
    ) {}

    /**
     * Toggle a project watch.
     */
    public function toggleProject(Request $request, int $projectId): RedirectResponse
    {
        $user = $this->requireUser($request);

        $project = Project::query()->findOrFail($projectId);

        $filters = $this->validatedFilters($request);

        $added = $this->watches->toggleProject($user, $project, $filters);

        return back()->with('status', $added
            ? 'Projet suivi.'
            : 'Projet retiré de vos abonnements.');
    }

    /**
     * Toggle an editor watch.
     */
    public function toggleEditor(Request $request, int $editorId): RedirectResponse
    {
        $user = $this->requireUser($request);

        $editor = Editor::query()->findOrFail($editorId);

        $filters = $this->validatedFilters($request);

        $added = $this->watches->toggleEditor($user, $editor, $filters);

        return back()->with('status', $added
            ? 'Éditeur suivi.'
            : 'Éditeur retiré de vos abonnements.');
    }

    /**
     * Focus and maturity filters of a watch: absent means site default.
     *
     * @return array{focus?: list<string>|null, maturities?: list<string>|null}
     */
    private function validatedFilters(Request $request): array
    {
        $payload = $request->validate([
            'focus' => ['nullable', 'array'],
            'focus.*' => ['in:'.self::FOCUS_VALUES],
            'maturity' => ['nullable', 'array'],
            'maturity.*' => ['in:'.self::MATURITY_VALUES],
        ]);

        return [
            'focus' => array_values((array) ($payload['focus'] ?? [])) ?: null,
            'maturities' => array_values((array) ($payload['maturity'] ?? [])) ?: null,
        ];
    }
}

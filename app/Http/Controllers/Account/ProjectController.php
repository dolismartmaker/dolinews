<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Enums\LinkType;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Projects\ProjectException;
use App\Domain\Dolinews\Projects\ProjectService;
use App\Http\Controllers\Concerns\ResolvesUser;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The contributor's project sheets, on the web (SPEC 4.2).
 *
 * Same ground as ProjectApiController and the same domain service: the sheet
 * used to be reachable through the API alone, which asked an editor to write
 * curl before their first announcement could name a project.
 *
 * The sheet carries NO Dolibarr compatibility (SPEC D1): it is persistent, so
 * anything dated it held would rot without anyone correcting it. Compatibility
 * lives on the article, with its date.
 */
class ProjectController extends Controller
{
    use ResolvesUser;

    public function __construct(
        private readonly ProjectService $projects,
        private readonly EditorService $editors,
    ) {}

    /**
     * The sheets of every editor the account belongs to.
     */
    public function index(Request $request): View
    {
        $user = $this->requireUser($request);

        return view('account.projects', [
            'projects' => Project::query()
                ->with(['editor', 'links', 'translations'])
                // A subquery rather than a list of ids: one round trip, and
                // the membership stays expressed by the relation itself.
                ->whereIn('editor_id', $user->editors()->select('editors.id'))
                ->orderBy('name')
                ->get(),
            'editors' => $user->editors()->orderBy('name')->get(),
        ]);
    }

    /**
     * Creation form.
     */
    public function create(Request $request): View
    {
        $user = $this->requireUser($request);

        return view('account.project-form', [
            'project' => null,
            'editors' => $user->editors()->orderBy('name')->get(),
        ]);
    }

    /**
     * Create a sheet for one of the account's editors.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $this->requireUser($request);

        abort_unless($user->isContributor(), 403);

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
            Log::warning('ProjectController: sheet creation refused, not a member of the editor', [
                'user' => $user->getKey(),
                'editor' => $payload['editor_id'],
            ]);

            abort(403);
        }

        $project = $this->projects->create($editor, $payload);

        return redirect()->route('account.projects.edit', $project)
            ->with('status', __('Fiche créée.'));
    }

    /**
     * Edit form: fields, typed links and translations of one sheet.
     */
    public function edit(Request $request, Project $project): View
    {
        $this->authorizeSheet($request, $project);

        return view('account.project-form', [
            'project' => $project->load(['editor', 'links', 'translations']),
            'editors' => $this->requireUser($request)->editors()->orderBy('name')->get(),
        ]);
    }

    /**
     * Update the reference fields of the sheet.
     */
    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeSheet($request, $project);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'summary' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'license' => ['nullable', 'string', 'max:50'],
            // An unmaintained sheet that says so stays honest; one that
            // pretends to be active is what rots (SPEC 4.2).
            'status' => ['required', 'in:active,unmaintained,archived'],
        ]);

        $this->projects->update($project, $payload);

        return redirect()->route('account.projects.edit', $project)
            ->with('status', __('Fiche enregistrée.'));
    }

    /**
     * Add a typed link. Shorteners are refused and a Dolistore sheet already
     * claimed by another project raises a conflict the moderation settles
     * (SPEC 8, 9.5).
     */
    public function storeLink(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeSheet($request, $project);

        $payload = $request->validate([
            'type' => ['required', 'in:dolistore,shop,demo,doc,repo,support,other'],
            'url' => ['required', 'url:http,https', 'max:2048'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->projects->addLink(
                $project,
                LinkType::from((string) $payload['type']),
                (string) $payload['url'],
                $payload['label'] ?? null,
            );
        } catch (ProjectException $e) {
            Log::warning('ProjectController: link refused', [
                'project' => $project->getKey(),
                'reason' => $e->getMessage(),
            ]);

            return redirect()->route('account.projects.edit', $project)
                ->withErrors(['url' => $e->getMessage()])
                ->withInput();
        }

        return redirect()->route('account.projects.edit', $project)
            ->with('status', __('Lien ajouté.'));
    }

    /**
     * Remove one of the sheet's links.
     */
    public function destroyLink(Request $request, Project $project, int $linkId): RedirectResponse
    {
        $this->authorizeSheet($request, $project);

        $this->projects->removeLink($project, $linkId);

        return redirect()->route('account.projects.edit', $project)
            ->with('status', __('Lien retiré.'));
    }

    /**
     * Add or update one translation of the sheet (SPEC D14).
     *
     * A sheet translation is not an article: it does not go through the
     * review, because it states nothing dated.
     */
    public function storeTranslation(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeSheet($request, $project);

        $payload = $request->validate([
            'locale' => ['required', 'string', 'size:5'],
            'name' => ['required', 'string', 'max:150'],
            'summary' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $this->projects->translate($project, (string) $payload['locale'], $payload);

        return redirect()->route('account.projects.edit', $project)
            ->with('status', __('Traduction enregistrée.'));
    }

    /**
     * Only a member of the owning editor touches a sheet.
     */
    private function authorizeSheet(Request $request, Project $project): void
    {
        $user = $this->requireUser($request);
        $editor = $project->editor;

        if ($editor === null || ! $this->editors->isMember($editor, $user)) {
            Log::warning('ProjectController: sheet access refused', [
                'user' => $user->getKey(),
                'project' => $project->getKey(),
            ]);

            abort(403);
        }
    }
}

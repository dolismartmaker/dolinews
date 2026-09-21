<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Public reading of a project sheet (SPEC 4.2) and an editor page.
 *
 * The sheet carries no Dolibarr compatibility (D1): its dated life is
 * the article list underneath it.
 */
class ProjectController extends Controller
{
    /**
     * A project sheet with its links and feed slice.
     */
    public function show(Request $request, string $slug): View
    {
        /** @var Project|null $project */
        $project = Project::query()
            ->where('slug', $slug)
            ->with(['links', 'translations', 'editor'])
            ->first();

        abort_if($project === null, 404);

        $locale = (string) $request->input('lang', app()->getLocale());
        $translation = $project->translations
            ->firstWhere('locale', $this->contentLocale($locale));

        $articles = $project->articles()
            ->published()
            ->orderByDesc('published_at')
            ->limit(20)
            ->get();

        return view('public.project', [
            'project' => $project,
            'translation' => $translation,
            'articles' => $articles,
            'attestations' => $project->attestations()
                ->orderByDesc('received_at')
                ->limit(5)
                ->get(),
        ]);
    }

    /**
     * An editor page: sheets and recent announcements (SPEC 6.4's
     * subscription target, also public).
     */
    public function editor(string $slug): View
    {
        /** @var Editor|null $editor */
        $editor = Editor::query()->where('slug', $slug)->first();

        abort_if($editor === null, 404);

        return view('public.editor', [
            'editor' => $editor,
            'projects' => $editor->projects()
                ->orderBy('name')
                ->get(),
            'articles' => $editor->articles()
                ->published()
                ->orderByDesc('published_at')
                ->limit(20)
                ->get(),
        ]);
    }

    /**
     * Map an interface locale to the closest content locale form
     * (fr -> fr_FR, en -> en_US).
     */
    private function contentLocale(string $locale): string
    {
        $short = substr($locale, 0, 2);

        return $short.'_'.strtoupper($short === 'en' ? 'US' : $short);
    }
}

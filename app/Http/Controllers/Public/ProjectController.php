<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Dolinews\Enums\Maturity;
use App\Domain\Dolinews\Feeds\FeedService;
use App\Domain\Dolinews\Markdown\ArticleMarkdown;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\ProjectMedia;
use App\Domain\Dolinews\Seo\PageLocale;
use App\Domain\Dolinews\Seo\StructuredData;
use App\Domain\Dolinews\Support\LanguageLabel;
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
    public function __construct(
        private readonly FeedService $feeds,
        private readonly StructuredData $structuredData,
        private readonly ArticleMarkdown $markdown,
    ) {}

    /**
     * A project sheet with its links and feed slice.
     */
    public function show(Request $request, string $slug): View
    {
        /** @var Project|null $project */
        $project = Project::query()
            ->where('slug', $slug)
            ->with(['links', 'translations', 'editor', 'logo', 'gallery.media'])
            ->first();

        abort_if($project === null, 404);

        $locale = (string) $request->input('lang', app()->getLocale());
        // PageLocale reads the configured content locales rather than
        // building the pair by hand: the service ships el_GR and pt_PT,
        // and a guess answered el_EL, so a Greek reader never saw the
        // Greek sheet that existed.
        $translation = $project->translations
            ->firstWhere('locale', PageLocale::full($locale));

        // One version per announcement, in the reader's language: the
        // sheet used to list every translation of the same entry.
        $articles = $this->feeds->localeSlice(
            $project->articles()->published()->orderByDesc('published_at')->getQuery(),
            $locale,
            20,
        );

        $logo = $project->logo?->url() ?? $project->editor?->logo?->url();

        $description = $translation !== null && $translation->description !== null
            ? $translation->description
            : $project->description;

        return view('public.project', [
            'project' => $project,
            'translation' => $translation,
            // Markdown through the article whitelist (SPEC D5): a sheet
            // is a presentation, and a presentation has subheadings and
            // a list of what the project does.
            'descriptionHtml' => $description === null || trim($description) === ''
                ? null
                : $this->markdown->render($description),
            // Said only when the sheet is not in the reader's language,
            // which is the case this page could not state at all before:
            // a Spanish reader was served French without a word
            // (SPEC 6.1).
            'sheetLanguage' => $translation !== null
                ? null
                : LanguageLabel::foreign($project->locale, $locale),
            'articles' => $articles,
            'latestStable' => $this->latestStable($project, $locale),
            'gallery' => $project->gallery->filter(
                static fn (ProjectMedia $entry): bool => $entry->media !== null,
            )->values(),
            'structuredData' => $this->structuredData->forProject($project, $translation, $logo),
            'ogImage' => $logo,
            'attestations' => $project->attestations()
                ->orderByDesc('received_at')
                ->limit(5)
                ->get(),
        ]);
    }

    /**
     * The newest stable version announced for the project, in the
     * reader's language when the announcement has one.
     *
     * Read from the published announcements at display time, never
     * stored on the sheet (D1): it is a dated fact about what was
     * announced, not the current state of the module, which the page
     * says by printing the date next to it.
     */
    private function latestStable(Project $project, string $locale): ?Article
    {
        /** @var Article|null $newest */
        $newest = $project->articles()
            ->published()
            ->where('maturity', Maturity::STABLE->value)
            ->whereNotNull('version')
            ->where('version', '!=', '')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        if ($newest === null) {
            return null;
        }

        // Every language version of that announcement, then one of
        // them: the reader's, the source otherwise (SPEC 6.1).
        return $this->feeds->onePerAnnouncement(
            $project->articles()
                ->published()
                ->where('translation_group_id', $newest->translation_group_id)
                ->get(),
            $locale,
        )->first();
    }

    /**
     * An editor page: sheets and recent announcements (SPEC 6.4's
     * subscription target, also public).
     */
    public function editor(Request $request, string $slug): View
    {
        /** @var Editor|null $editor */
        $editor = Editor::query()->where('slug', $slug)->first();

        abort_if($editor === null, 404);

        $logo = $editor->logo?->url();

        return view('public.editor', [
            'editor' => $editor,
            'structuredData' => $this->structuredData->forEditor($editor, $logo),
            'ogImage' => $logo,
            'projects' => $editor->projects()
                ->orderBy('name')
                ->get(),
            'articles' => $this->feeds->localeSlice(
                $editor->articles()->published()->orderByDesc('published_at')->getQuery(),
                (string) $request->input('lang', app()->getLocale()),
                20,
            ),
        ]);
    }
}

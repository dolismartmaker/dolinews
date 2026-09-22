<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Dolinews\Articles\ArticleException;
use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Articles\QuotaException;
use App\Domain\Dolinews\Articles\RevisionService;
use App\Domain\Dolinews\Articles\TranslationService;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Translation\AutoTranslationException;
use App\Domain\Dolinews\Translation\AutoTranslationService;
use App\Domain\Dolinews\Translation\TranslationRouter;
use App\Http\Controllers\Concerns\ResolvesUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The author's workspace: drafts, submission, translations and
 * post-publication revisions (SPEC 5). Every mutation goes through the
 * domain services; this controller only wires HTTP to them.
 */
class AuthorController extends Controller
{
    use ResolvesUser;

    public function __construct(
        private readonly ArticleService $articles,
        private readonly TranslationService $translations,
        private readonly RevisionService $revisions,
        private readonly EditorService $editors,
        private readonly AutoTranslationService $machineTranslations,
        private readonly TranslationRouter $router,
    ) {}

    /**
     * The author's announcements, every status, latest activity first.
     *
     * ONE line per announcement and not per row of `articles`: an
     * announcement declined into ten languages is one piece of work, the
     * same unit the queue ceiling counts (SPEC 5.3), and listing its ten
     * versions side by side buries everything else.
     *
     * The line stands for the group, and what it shows is what this
     * account wrote in it: the source when it wrote it, its own
     * translation otherwise. That second case is the mandated translator
     * (SPEC 5.6), who does not own the source it translates - keeping
     * only sources here would empty its workspace of everything it has
     * in hand. MIN(id) gives exactly that rule, a source being created
     * before any of its translations.
     *
     * Ordered on the latest activity OF THE GROUP, not of the line: an
     * announcement translated nine times this morning would otherwise sit
     * at the bottom, which is where the work in progress must not be.
     */
    public function index(Request $request): View
    {
        $user = $this->requireUser($request);

        $groups = Article::query()
            ->selectRaw('MIN(id) as representative_id, MAX(updated_at) as last_activity')
            ->where('author_user_id', $user->getKey())
            ->groupBy('translation_group_id');

        $articles = Article::query()
            ->joinSub($groups, 'author_groups', 'articles.id', '=', 'author_groups.representative_id')
            ->select('articles.*')
            ->orderByDesc('author_groups.last_activity')
            ->paginate(20);

        return view('account.articles', [
            'articles' => $articles,
            // Every version of the listed groups, this account's and the
            // others': what the line reports is the state of the
            // announcement, not of one contributor's share of it. One
            // grouped count for the page, never one query per line.
            'versionCounts' => Article::query()
                ->whereIn('translation_group_id', $articles->pluck('translation_group_id')->all())
                ->whereNull('deleted_at')
                ->selectRaw('translation_group_id, count(*) as versions')
                ->groupBy('translation_group_id')
                ->get()
                ->mapWithKeys(static fn (Article $row): array => [
                    (string) $row->translation_group_id => (int) $row->getAttribute('versions'),
                ])
                ->all(),
            'editors' => $user->editors()->orderBy('name')->get(),
        ]);
    }

    /**
     * Draft creation form.
     */
    public function create(Request $request): View
    {
        $user = $this->requireUser($request);

        return view('account.article-form', [
            'article' => null,
            'editors' => $user->editors()->orderBy('name')->get(),
            'projects' => $this->projectsOf($user),
        ]);
    }

    /**
     * Store a new draft.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $this->requireUser($request);
        $editor = $this->resolveEditor($request, $user);

        $payload = $this->validatedArticle($request);

        $article = $this->articles->createDraft($user, $editor, $payload);

        return redirect()->route('account.articles.edit', $article)
            ->with('status', __('Brouillon créé.'));
    }

    /**
     * Edit form (draft, rejected or pending article).
     */
    public function edit(Request $request, Article $article): View
    {
        $this->assertOwns($request, $article);

        $user = $this->requireUser($request);

        return view('account.article-form', [
            'article' => $article,
            'editors' => $user->editors()->orderBy('name')->get(),
            'projects' => $this->projectsOf($user),
        ]);
    }

    /**
     * Apply an edit. A pending edit counts as a resubmission and resets
     * the review accords (SPEC 5.1).
     */
    public function update(Request $request, Article $article): RedirectResponse
    {
        $user = $this->requireUser($request);

        try {
            $this->articles->edit($article, $user, $this->validatedArticle($request, false));
        } catch (ArticleException $e) {
            return back()->withErrors(['title' => $e->getMessage()]);
        }

        return redirect()->route('account.articles.edit', $article)
            ->with('status', __('Article mis à jour.'));
    }

    /**
     * Submit to the review circuit (SPEC 5.1): the quota is reserved
     * here, before the article enters the queue.
     */
    public function submit(Request $request, Article $article): RedirectResponse
    {
        $user = $this->requireUser($request);

        try {
            $this->articles->submit($article, $user);
        } catch (QuotaException $e) {
            return back()->withErrors(['submit' => $e->getMessage()]);
        } catch (ArticleException $e) {
            return back()->withErrors(['submit' => $e->getMessage()]);
        }

        return redirect()->route('account.articles')
            ->with('status', __('Article soumis : il entre dans la file de revue.'));
    }

    /**
     * Propose a revision of a published article (SPEC 5.4).
     */
    public function proposeRevision(Request $request, Article $article): RedirectResponse
    {
        $user = $this->requireUser($request);

        $payload = $request->validate([
            'summary' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:65535'],
            'title' => ['nullable', 'string', 'max:255'],
            'motive' => ['required', 'string', 'max:255'],
        ]);

        $changes = array_filter(
            $payload,
            static fn ($value, string $key): bool => in_array($key, ['summary', 'body', 'title'], true)
                && is_string($value) && trim($value) !== '',
            ARRAY_FILTER_USE_BOTH,
        );

        try {
            $this->revisions->propose($article, $user, $changes, (string) $payload['motive']);
        } catch (ArticleException $e) {
            return back()->withErrors(['revision' => $e->getMessage()]);
        }

        return back()->with('status', __('Révision proposée : elle repasse par la revue.'));
    }

    /**
     * Submit a translation of one of the author's announcements
     * (SPEC 5.2, D14): a full article, same review circuit, no token.
     */
    public function storeTranslation(Request $request, Article $article): RedirectResponse
    {
        $user = $this->requireUser($request);

        $payload = $request->validate([
            'locale' => ['required', 'string', 'size:5'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:65535'],
        ]);

        // Not assertOwns: a mandated translator writes on an
        // announcement that is not theirs (SPEC 5.6). The service holds
        // the rule, this is here for the status code.
        abort_unless(
            $this->translations->canTranslate($article, $user, (string) $payload['locale']),
            403,
        );

        try {
            $translation = $this->translations->submitTranslation(
                $article,
                $user,
                (string) $payload['locale'],
                $payload,
            );
        } catch (ArticleException $e) {
            return back()->withErrors(['locale' => $e->getMessage()]);
        }

        return redirect()->route('account.articles.edit', $translation)
            ->with('status', __('Traduction créée : soumettez-la quand elle est prête.'));
    }

    /**
     * The form to write a language version of a published announcement.
     *
     * Its own page rather than a block of the article form: the account
     * filling it is often not the one that wrote the announcement, and
     * has no business on the edit screen of an article it does not own.
     */
    public function createTranslation(Request $request, Article $article): View
    {
        $user = $this->requireUser($request);

        abort_unless($this->translations->canTranslate($article, $user), 403);

        $source = $article->isTranslation() ? ($article->sourceArticle() ?? $article) : $article;

        /** @var array<string, Article> $versions */
        $versions = $source->translations()->get()->keyBy('locale')->all();

        return view('account.translation-form', [
            'source' => $source,
            'versions' => $versions,
            'existing' => array_keys($versions),
            'contentLocales' => (array) config('dolinews.content_locales', []),
            // The machine route is offered to the editor itself only: a
            // version it produces is published without review, which a
            // mandated translator may not trigger (SPEC 5.1/5.7).
            'canAskMachine' => $source->status === ArticleStatus::PUBLISHED
                && $this->translations->belongsToEditor($source, $user)
                && $this->router->resolve($source->editor, onDemand: true)['engine'] !== null,
        ]);
    }

    /**
     * Produce one language version of a published announcement with the
     * translation engine, on the editor's explicit demand (SPEC 5.7).
     *
     * Synchronous, and deliberately: the editor clicked to see a result,
     * and a queued job would leave the screen unchanged with nothing to
     * read. A refusal is stated with its reason - a spent allowance is
     * not a breakage.
     */
    public function storeAutomaticTranslation(Request $request, Article $article): RedirectResponse
    {
        $user = $this->requireUser($request);

        $payload = $request->validate([
            'locale' => ['required', 'string', 'size:5'],
        ]);

        $source = $article->isTranslation() ? ($article->sourceArticle() ?? $article) : $article;

        // Its own editor, never a mandated translator: what comes out is
        // published without review under the editor's name.
        abort_unless($this->translations->belongsToEditor($source, $user), 403);

        try {
            $translation = $this->machineTranslations->translateInto($source, (string) $payload['locale']);
        } catch (AutoTranslationException $e) {
            return back()->withErrors(['locale' => $e->getMessage()]);
        }

        return back()->with('status', __('Version :locale publiée.', ['locale' => $translation->locale]));
    }

    /**
     * Assert the current user authored the article.
     */
    private function assertOwns(Request $request, Article $article): void
    {
        $user = $this->requireUser($request);

        abort_unless($article->author_user_id === $user->getKey(), 403);
    }

    /**
     * The editor to publish for: an editor the author belongs to.
     */
    private function resolveEditor(Request $request, User $user): Editor
    {
        $payload = $request->validate([
            'editor_id' => ['required', 'integer', 'exists:editors,id'],
        ]);

        /** @var Editor|null $editor */
        $editor = Editor::query()->find((int) $payload['editor_id']);

        abort_if($editor === null, 404);

        abort_unless($this->editors->isMember($editor, $user), 403);

        return $editor;
    }

    /**
     * Projects of the author's editors, for the article's project field.
     *
     * @return array<int, Project>
     */
    private function projectsOf(User $user): array
    {
        return $user->editors()
            ->with('projects')
            ->get()
            ->flatMap(static fn ($editor) => $editor->projects->all())
            ->values()
            ->all();
    }

    /**
     * Validated article fields.
     *
     * @return array<string, mixed>
     */
    private function validatedArticle(Request $request, bool $withEditor = true): array
    {
        $rules = [
            'type' => ['required', 'in:release,announcement'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'focus' => ['nullable', 'in:doc,cleanup,feature_minor,feature_major,bugfix_minor,bugfix_major,security,compat,eol'],
            'title' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:32'],
            'summary' => ['required', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:65535'],
            'locale' => ['required', 'string', 'size:5'],
            'dolibarr_min' => ['nullable', 'integer', 'between:1,99'],
            'dolibarr_max' => ['nullable', 'integer', 'between:1,99'],
            'maturity' => ['nullable', 'in:alpha,beta,rc,stable,deprecated'],
            'compat_status' => ['nullable', 'in:declared,tested,experimental'],
        ];

        if ($withEditor) {
            $rules['editor_id'] = ['required', 'integer', 'exists:editors,id'];
        }

        $payload = $request->validate($rules);

        unset($payload['editor_id']);

        return $payload;
    }
}

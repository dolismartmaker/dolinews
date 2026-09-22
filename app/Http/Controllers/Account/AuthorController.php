<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Dolinews\Articles\ArticleException;
use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Articles\QuotaException;
use App\Domain\Dolinews\Articles\RevisionService;
use App\Domain\Dolinews\Articles\TranslationService;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
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
    ) {}

    /**
     * The author's articles, every status, newest first.
     */
    public function index(Request $request): View
    {
        $user = $this->requireUser($request);

        return view('account.articles', [
            'articles' => Article::query()
                ->where('author_user_id', $user->getKey())
                ->orderByDesc('updated_at')
                ->paginate(20),
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

        return view('account.translation-form', [
            'source' => $source,
            'existing' => $source->translations()->pluck('locale')->all(),
            'contentLocales' => (array) config('dolinews.content_locales', []),
        ]);
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

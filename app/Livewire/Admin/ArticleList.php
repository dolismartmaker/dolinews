<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Core\Audit\AuditLogger;
use App\Domain\Dolinews\Articles\ArticleException;
use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Moderation\ModerationException;
use App\Domain\Dolinews\Moderation\ModerationService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;

/**
 * Article list with moderation acts (SPEC 9.3): hide, unhide, withdraw,
 * restore. Every act is journalled with motive and rule; the act form
 * is part of this screen because the generic list stays thin.
 *
 * Filing an announcement under a project sheet lives here too, and is
 * not one of those acts: it changes no word of a published text, it
 * ranges it. It therefore skips the review, where a correction would go
 * through it (SPEC 5.4), and is reserved to the super admin.
 *
 * One line per announcement, on its source version: an announcement
 * declined into ten languages would otherwise fill the page with ten
 * lines. The language versions stay one click away in the act panel,
 * and every act still targets a single article - withdrawing must stay
 * possible on any of them (SPEC 9.6).
 *
 * @extends AdminList<Article>
 */
class ArticleList extends AdminList
{
    public ?int $actArticleId = null;

    public string $actKind = 'hide';

    public string $actMotive = '';

    public string $actRule = '';

    public bool $actConflict = false;

    public bool $actLegal = false;

    /**
     * Filing form state: the sheet to file under, empty meaning none,
     * and the motive every act of exploitation carries.
     */
    public string $linkProjectId = '';

    public string $linkMotive = '';

    /**
     * Narrow the list to the announcements filed under no sheet, where a
     * misfiled one is found and filed. Kept in the URL so the dashboard
     * tile leads straight to it.
     */
    #[Url(as: 'sans_fiche')]
    public bool $withoutSheet = false;

    /**
     * Refused announcements are left out unless asked for: a refusal is
     * kept with its review thread, the motive and the rule a contest
     * rests on (SPEC 9.4), so the list hides them instead.
     */
    #[Url(as: 'refuses')]
    public bool $showRejected = false;

    public function mount(): void
    {
        $this->mountAuthorizeAdmin();
    }

    public function updatedWithoutSheet(): void
    {
        $this->resetPage();
        $this->resetSelection();
    }

    public function updatedShowRejected(): void
    {
        $this->resetPage();
        $this->resetSelection();
    }

    /**
     * Announcements filed under no sheet, for the label of the filter,
     * counted as the list shows them.
     */
    public function withoutSheetCount(): int
    {
        return Article::query()
            ->withoutSheet()
            ->when(! $this->showRejected, static fn (Builder $query): Builder => $query->where('status', '!=', ArticleStatus::REJECTED))
            ->count();
    }

    public function filtersView(): ?string
    {
        return 'livewire.admin.partials.article-filters';
    }

    public function emptyText(): string
    {
        if ($this->withoutSheet && $this->search === '') {
            return __('Aucune annonce sans fiche.');
        }

        if (! $this->showRejected && $this->search !== '') {
            return __('Aucun résultat pour cette recherche parmi les annonces non refusées.');
        }

        return parent::emptyText();
    }

    /**
     * @return Builder<Article>
     */
    protected function baseQuery(): Builder
    {
        // Withdrawn versions are not counted, as on the author's own list:
        // the column says what the announcement offers, the panel lists
        // every version for the moderator.
        $translations = DB::table('articles as versions')
            ->selectRaw('count(*)')
            ->whereColumn('versions.translation_group_id', 'articles.translation_group_id')
            ->where('versions.is_source', false)
            ->whereNull('versions.deleted_at');

        // The sheet's name as a column of its own, so the list sorts on
        // it: ascending, the announcements filed under no sheet come first.
        $sheet = DB::table('projects')
            ->select('projects.name')
            ->whereColumn('projects.id', 'articles.project_id');

        return Article::query()
            ->select('articles.*')
            ->selectSub($translations, 'translations_count')
            ->selectSub($sheet, 'project_name')
            ->where('articles.is_source', true)
            ->when($this->withoutSheet, static fn (Builder $query): Builder => $query->withoutSheet())
            ->when(! $this->showRejected, static fn (Builder $query): Builder => $query->where('articles.status', '!=', ArticleStatus::REJECTED))
            ->with(['editor', 'project']);
    }

    /**
     * A title searched in another language finds its announcement: the
     * line is the source, the term may be in any of its versions.
     *
     * @param  Builder<Article>  $query
     * @param  list<string>  $columns
     * @return Builder<Article>
     */
    protected function applySearch(Builder $query, array $columns): Builder
    {
        $term = trim($this->search);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(' ', '%', $term).'%';

        return $query->where(function (Builder $inner) use ($columns, $like): void {
            parent::applySearch($inner, $columns)->orWhereExists(function (QueryBuilder $versions) use ($like): void {
                $versions->selectRaw('1')
                    ->from('articles as versions')
                    ->whereColumn('versions.translation_group_id', 'articles.translation_group_id')
                    ->where('versions.title', 'like', $like);
            });
        });
    }

    public function heading(): string
    {
        return __('Articles');
    }

    public function intro(): string
    {
        return __('Une ligne par annonce, sur sa version d\'origine, refusées masquées sauf demande. Les versions traduites s\'ouvrent depuis le panneau de modération. Un article publié ne se modifie pas en place : une correction est une révision, elle repasse par la revue.');
    }

    /**
     * @return list<array{key: string, label: string, sortable: bool, searchable: bool}>
     */
    protected function columns(): array
    {
        return [
            ['key' => 'id', 'label' => __('Id'), 'sortable' => true, 'searchable' => false],
            ['key' => 'title', 'label' => __('Titre'), 'sortable' => true, 'searchable' => true],
            ['key' => 'project_name', 'label' => __('Fiche'), 'sortable' => true, 'searchable' => false],
            ['key' => 'translations_count', 'label' => __('Versions traduites'), 'sortable' => true, 'searchable' => false],
            ['key' => 'status', 'label' => __('Statut'), 'sortable' => true, 'searchable' => true],
            ['key' => 'published_at', 'label' => __('Publié le'), 'sortable' => true, 'searchable' => false],
            ['key' => 'deleted_at', 'label' => __('Retiré le'), 'sortable' => true, 'searchable' => false],
        ];
    }

    public function formatCell(Model $row, string $key): string
    {
        if ($key === 'project_name' && $row->getAttribute($key) === null) {
            return __('Aucune fiche');
        }

        return parent::formatCell($row, $key);
    }

    public function actions(): array
    {
        return [
            ['label' => __('Modérer'), 'method' => 'openAct'],
        ];
    }

    public function panelView(): ?string
    {
        return 'livewire.admin.partials.article-act';
    }

    /**
     * The article the open act targets, for the panel to name it. Null when no
     * act is open.
     */
    public function actTarget(): ?Article
    {
        if ($this->actArticleId === null) {
            return null;
        }

        // deleted_at is a plain column here, not a soft-delete scope: a
        // withdrawn article stays in the list, which is what lets it be
        // restored from this very screen.
        return Article::query()->find($this->actArticleId);
    }

    /**
     * Every language version of the open announcement, withdrawn ones
     * included: the panel is where a moderator reaches a translation the
     * list no longer shows, and restoring one must stay possible.
     *
     * @return Collection<int, Article>
     */
    public function actVersions(): Collection
    {
        $article = $this->actTarget();

        if ($article === null) {
            /** @var Collection<int, Article> $empty */
            $empty = new Collection;

            return $empty;
        }

        return app(ArticleService::class)->translationGroup($article);
    }

    /**
     * Open the moderation act form on one article.
     */
    public function openAct(int $articleId): void
    {
        $this->actArticleId = $articleId;
        $this->actKind = 'hide';
        $this->actMotive = '';
        $this->actRule = '';
        $this->actConflict = false;
        $this->actLegal = false;

        $article = Article::query()->findOrFail($articleId);
        $this->linkProjectId = (string) ($article->project_id ?? '');
        $this->linkMotive = '';
        $this->resetErrorBag();
    }

    /**
     * The sheets the open article may be filed under: those of its own
     * editor. Filing under someone else's sheet would be a claim, which
     * has its own circuit (SPEC 9.5).
     *
     * @return Collection<int, Project>
     */
    public function linkableProjects(): Collection
    {
        $article = $this->actTarget();

        if ($article === null) {
            /** @var Collection<int, Project> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var Collection<int, Project> $projects */
        $projects = Project::query()
            ->where('editor_id', $article->editor_id)
            ->orderBy('name')
            ->get();

        return $projects;
    }

    /**
     * How many articles the filing would move: the announcement and its
     * language versions, which share the sheet.
     */
    public function linkGroupSize(): int
    {
        $article = $this->actTarget();

        if ($article === null) {
            return 0;
        }

        return app(ArticleService::class)->translationGroup($article)->count();
    }

    /**
     * File the open announcement under a sheet, or take it out of one.
     *
     * Reserved to the super admin where the moderation acts above are
     * open to the team: nothing here is urgent, and a misfiled
     * announcement is an exploitation matter, not a sanction.
     */
    public function linkProject(): void
    {
        /** @var User|null $actor */
        $actor = auth('web')->user();

        abort_unless($actor instanceof User && $actor->is_super_admin, 403);

        $this->validate([
            'linkProjectId' => ['nullable', 'integer', 'exists:projects,id'],
            'linkMotive' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $article = Article::query()->findOrFail($this->actArticleId);

        $project = $this->linkProjectId === ''
            ? null
            : Project::query()->find((int) $this->linkProjectId);

        $before = $article->project_id;

        try {
            $filed = app(ArticleService::class)->linkProject($article, $project);
        } catch (ArticleException $e) {
            Log::warning('ArticleList: filing refused', ['reason' => $e->getMessage()]);
            $this->addError('linkProjectId', $e->getMessage());

            return;
        }

        // Not a moderation_log entry: the action enum of SPEC 4.5 is
        // closed and lists acts taken against a content, where this one
        // files it. The transverse audit carries it, with its motive.
        app(AuditLogger::class)->log(
            $project === null ? 'article.project_detached' : 'article.project_attached',
            $article,
            [
                'translation_group_id' => $article->translation_group_id,
                'project_id_before' => $before,
                'project_id_after' => $project?->getKey(),
                'articles' => $filed,
                'motive' => $this->linkMotive,
            ],
        );

        $this->linkMotive = '';
        $this->success($project === null
            ? __('Annonce détachée de sa fiche, acte journalisé.')
            : __('Annonce rattachée à la fiche, acte journalisé.'));
    }

    /**
     * Close the act panel without acting.
     */
    public function closeAct(): void
    {
        $this->actArticleId = null;
        $this->resetErrorBag();
    }

    /**
     * Apply the chosen act. Withdrawal acts go through whatever the
     * conflict of interest: urgency first (SPEC 9.6).
     */
    public function applyAct(ModerationService $moderation): void
    {
        /** @var User|null $actor */
        $actor = auth('web')->user();

        abort_unless($actor !== null && ($actor->isModerator() || $actor->is_super_admin), 403);

        if (($refusal = $this->impersonationRefusal('article.act')) !== null) {
            $this->error($refusal);

            return;
        }

        $this->validate([
            'actMotive' => ['required', 'string', 'min:10', 'max:2000'],
            'actRule' => ['required', 'string', 'max:20'],
            'actKind' => ['required', 'in:hide,unhide,delete,restore'],
        ]);

        $article = Article::query()->findOrFail($this->actArticleId);

        try {
            match ($this->actKind) {
                'hide' => $moderation->hideArticle($article, $actor, $this->actMotive, $this->actRule, $this->actConflict, $this->actLegal),
                'unhide' => $moderation->unhideArticle($article, $actor, $this->actMotive, $this->actRule),
                'delete' => $moderation->deleteArticle($article, $actor, $this->actMotive, $this->actRule, $this->actConflict, $this->actLegal),
                'restore' => $moderation->restoreArticle($article, $actor, $this->actMotive, $this->actRule),
                default => throw new \LogicException('Unknown moderation act.'),
            };
        } catch (ModerationException|ArticleException $e) {
            Log::warning('ArticleList: moderation act refused', ['reason' => $e->getMessage()]);
            $this->addError('actMotive', $e->getMessage());

            return;
        }

        $this->actArticleId = null;
        $this->success(__('Acte appliqué et journalisé.'));
    }
}

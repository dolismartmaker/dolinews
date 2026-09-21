<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Core\Admin\Livewire\BaseListComponent;
use App\Domain\Dolinews\Articles\ArticleException;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Moderation\ModerationException;
use App\Domain\Dolinews\Moderation\ModerationService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Article list with moderation acts (SPEC 9.3): hide, unhide, withdraw,
 * restore. Every act is journalled with motive and rule; the act form
 * is part of this screen because the generic list stays thin.
 */
class ArticleList extends BaseListComponent
{
    public string $sortField = 'id';

    public string $sortDir = 'desc';

    public ?int $actArticleId = null;

    public string $actKind = 'hide';

    public string $actMotive = '';

    public string $actRule = '';

    public bool $actConflict = false;

    public bool $actLegal = false;

    public function mount(): void
    {
        $this->mountAuthorizeAdmin();
    }

    /**
     * @return Builder<Article>
     */
    protected function baseQuery(): Builder
    {
        return Article::query()
            ->select('articles.*')
            ->with(['editor', 'project']);
    }

    /**
     * @return list<array{key: string, label: string, sortable: bool, searchable: bool}>
     */
    protected function columns(): array
    {
        return [
            ['key' => 'id', 'label' => 'Id', 'sortable' => true, 'searchable' => false],
            ['key' => 'title', 'label' => 'Titre', 'sortable' => true, 'searchable' => true],
            ['key' => 'status', 'label' => 'Statut', 'sortable' => true, 'searchable' => true],
            ['key' => 'published_at', 'label' => 'Publié le', 'sortable' => true, 'searchable' => false],
            ['key' => 'deleted_at', 'label' => 'Retiré le', 'sortable' => true, 'searchable' => false],
        ];
    }

    public function actions(): array
    {
        return [
            ['label' => 'Modérer', 'method' => 'openAct'],
        ];
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
    }
}

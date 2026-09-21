<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Core\Admin\Livewire\BaseListComponent;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Review\ReviewStats;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The review queue (SPEC 5.1): pending submissions, security first,
 * then oldest first. Security entries jump the queue; abusing that
 * focus to cut in line is a numbered usage-rule offence (SPEC 9.3).
 */
class ReviewQueue extends BaseListComponent
{
    public function mount(): void
    {
        $this->mountAuthorizeAdmin();
    }

    /**
     * The priority-ordered queue itself (scopeReviewQueue).
     *
     * @return Builder<Article>
     */
    protected function baseQuery(): Builder
    {
        return Article::query()
            ->select('articles.*')
            ->with(['editor', 'project', 'author'])
            ->whereNull('deleted_at')
            ->where('status', ArticleStatus::PENDING->value);
    }

    /**
     * @return list<array{key: string, label: string, sortable: bool, searchable: bool}>
     */
    protected function columns(): array
    {
        // Searchable stays false throughout: the queue is small and
        // strictly ordered by priority, free-text search would fight
        // that order.
        return [
            ['key' => 'id', 'label' => __('Id'), 'sortable' => false, 'searchable' => false],
            ['key' => 'title', 'label' => __('Titre'), 'sortable' => false, 'searchable' => false],
            ['key' => 'focus', 'label' => __('Focus'), 'sortable' => false, 'searchable' => false],
            ['key' => 'locale', 'label' => __('Langue'), 'sortable' => false, 'searchable' => false],
            ['key' => 'submitted_at', 'label' => __('Soumis le'), 'sortable' => false, 'searchable' => false],
        ];
    }

    public function heading(): string
    {
        return __('File de revue');
    }

    /**
     * The queue's own ordering (security first, oldest first, SPEC 5.1),
     * not the base component's. The base rows() contract stays
     * Model-generic; the builder and the view are Article-typed.
     *
     * @return LengthAwarePaginator<int, Model>
     */
    protected function rows(): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Model> $paginated */
        $paginated = Article::query()
            ->select('articles.*')
            ->with(['editor', 'project', 'author'])
            ->whereNull('deleted_at')
            ->where('status', ArticleStatus::PENDING->value)
            ->reviewQueue()
            ->paginate($this->perPage);

        return $paginated;
    }

    /**
     * Public measures shown alongside the queue (SPEC 5.1): observed
     * median, never a promise, and the age of the oldest pending entry.
     */
    public function render(): View
    {
        return view('livewire.admin.review-queue', [
            'rows' => $this->rows(),
            'columns' => $this->columns(),
            'actions' => [],
            'heading' => $this->heading(),
            'medianSeconds' => app(ReviewStats::class)->observedMedianSeconds(),
            'oldestPendingDays' => app(ReviewStats::class)->oldestPendingAgeDays(),
        ])->layout('core.admin.layout')->title($this->heading());
    }
}

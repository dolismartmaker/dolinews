<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Review\ReviewStats;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

/**
 * The review queue (SPEC 5.1): pending submissions, security first,
 * then oldest first. Security entries jump the queue; abusing that
 * focus to cut in line is a numbered usage-rule offence (SPEC 9.3).
 *
 * @extends AdminList<Article>
 */
class ReviewQueue extends AdminList
{
    /**
     * Whether the queue is narrowed to the languages this moderator
     * declared reading (SPEC 5.1).
     *
     * On by default for someone who declared a selection, since that
     * declaration is exactly the statement "the rest is not for me". It
     * is a display filter and nothing else: unchecking shows the whole
     * queue, which stays open to every moderator.
     */
    public bool $onlyMyLanguages = true;

    public function mount(): void
    {
        $this->mountAuthorizeAdmin();
    }

    /**
     * Reset pagination when the language filter moves: page four of a
     * narrowed queue is usually empty.
     */
    public function updatedOnlyMyLanguages(): void
    {
        $this->resetPage();
        $this->resetSelection();
    }

    /**
     * The content locales the current moderator declared, expanded to
     * every locale sharing their base language: a moderator who reads
     * Spanish reads es_ES whatever shape the declaration took.
     *
     * Empty means no restriction, the state an account starts in.
     *
     * @return list<string>
     */
    public function declaredLocales(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $declared = $user->review_locales ?? [];

        if ($declared === []) {
            return [];
        }

        $languages = array_map(
            static fn (string $locale): string => User::baseLanguage($locale),
            $declared,
        );

        return array_values(array_filter(
            (array) config('dolinews.content_locales', []),
            static fn (string $locale): bool => in_array(User::baseLanguage($locale), $languages, true),
        ));
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
     * never the sort of a generic list, and the language filter.
     *
     * @return Builder<Article>
     */
    protected function listQuery(): Builder
    {
        $locales = $this->onlyMyLanguages ? $this->declaredLocales() : [];

        return $this->baseQuery()
            ->when($locales !== [], fn (Builder $query) => $query->whereIn('locale', $locales))
            ->reviewQueue();
    }

    /**
     * Public measures shown alongside the queue (SPEC 5.1): observed
     * median, never a promise, and the age of the oldest pending entry.
     */
    public function render(): View
    {
        return view('livewire.admin.review-queue', [
            'rows' => $this->rows(),
            'headers' => [
                ...$this->tableHeaders(),
                ['key' => 'author', 'label' => __('Auteur'), 'sortable' => false],
            ],
            'heading' => $this->heading(),
            'medianSeconds' => app(ReviewStats::class)->observedMedianSeconds(),
            'oldestPendingDays' => app(ReviewStats::class)->oldestPendingAgeDays(),
            'hasDeclaredLocales' => $this->declaredLocales() !== [],
        ])->title($this->heading());
    }
}

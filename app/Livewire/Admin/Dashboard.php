<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Core\Admin\Concerns\AuthorizesAdmin;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\ContributorProof;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Review\BootstrapPhaseService;
use App\Domain\Dolinews\Review\ReviewStats;
use App\Models\ApiRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin dashboard (socle kit): read-only DoliNews counters, thin by
 * construction (S15).
 */
#[Layout('core.admin.layout')]
class Dashboard extends Component
{
    use AuthorizesAdmin;

    public function mount(): void
    {
        $this->mountAuthorizeAdmin();
    }

    /**
     * Articles awaiting review, the queue the team works through.
     */
    public function pendingArticles(): int
    {
        return Article::query()
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Active moderators (SPEC 9.1, floor of six).
     */
    public function activeModerators(): int
    {
        return User::query()->where('is_moderator', true)->where('active', true)->count();
    }

    /**
     * Verified contributors (active proofs).
     */
    public function activeContributors(): int
    {
        return ContributorProof::query()->whereNull('revoked_at')->count();
    }

    /**
     * Published articles, bootstrap ones flagged for the durable public
     * mention (SPEC 5.1).
     */
    public function publishedArticles(): int
    {
        return Article::query()->where('status', 'published')->count();
    }

    /**
     * Whether the bootstrap phase is still open (SPEC 5.1).
     */
    public function bootstrapOpen(): bool
    {
        return app(BootstrapPhaseService::class)->isOpen();
    }

    /**
     * Sheets and API calls for context.
     */
    public function projectCount(): int
    {
        return Project::query()->count();
    }

    public function apiCallsThisMonth(): int
    {
        return ApiRequest::query()
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->count();
    }

    /**
     * Observed median delay, the figure also shown publicly (SPEC 5.1).
     */
    public function medianSeconds(): ?float
    {
        return app(ReviewStats::class)->observedMedianSeconds();
    }

    public function render(): View
    {
        return view('livewire.admin.dashboard', [
            'pendingArticles' => $this->pendingArticles(),
            'activeModerators' => $this->activeModerators(),
            'activeContributors' => $this->activeContributors(),
            'publishedArticles' => $this->publishedArticles(),
            'bootstrapOpen' => $this->bootstrapOpen(),
            'projectCount' => $this->projectCount(),
            'apiCallsThisMonth' => $this->apiCallsThisMonth(),
            'medianSeconds' => $this->medianSeconds(),
        ])->title(__('Tableau de bord'));
    }
}

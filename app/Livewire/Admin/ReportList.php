<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Core\Admin\Livewire\BaseListComponent;
use App\Domain\Dolinews\Articles\ArticleException;
use App\Domain\Dolinews\Enums\ReportStatus;
use App\Domain\Dolinews\Models\ContentReport;
use App\Domain\Dolinews\Moderation\ModerationException;
use App\Domain\Dolinews\Moderation\ModerationService;
use App\Domain\Dolinews\Moderation\ReportException;
use App\Domain\Dolinews\Moderation\ReportService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The reports queue (SPEC 9.9): what readers say slipped through the
 * review, and what the team did about it.
 *
 * The screen carries the moderation act itself on an article, and not
 * just the closing of the report: a moderator who has just read why a
 * content is wrong should not have to find it again on another screen,
 * which is how a report gets read and then forgotten. The act still goes
 * through ModerationService, so it lands in moderation_log with its
 * numbered rule and its motive (SPEC 9.4) - this screen never writes a
 * moderation act of its own.
 */
class ReportList extends BaseListComponent
{
    public string $sortField = 'id';

    public string $sortDir = 'desc';

    /**
     * Closed reports are out of the way by default: this is a queue.
     */
    public bool $showHandled = false;

    public ?int $actReportId = null;

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
     * @return Builder<ContentReport>
     */
    protected function baseQuery(): Builder
    {
        $query = ContentReport::query()
            ->select('content_reports.*')
            ->with(['article', 'project', 'handledBy']);

        if (! $this->showHandled) {
            $query->open();
        }

        return $query;
    }

    public function heading(): string
    {
        return __('Signalements');
    }

    public function intro(): string
    {
        return __('Ce que les lecteurs signalent sur les contenus publiés. Un signalement ne vaut pas manquement : l\'acte éventuel se prend ici même, avec sa règle numérotée, et part au journal de modération.');
    }

    /**
     * Reset pagination when the closed ones are folded back in or out.
     */
    public function updatedShowHandled(): void
    {
        $this->resetPage();
    }

    /**
     * @return list<array{key: string, label: string, sortable: bool, searchable: bool}>
     */
    protected function columns(): array
    {
        return [
            ['key' => 'id', 'label' => __('Id'), 'sortable' => true, 'searchable' => false],
            ['key' => 'created_at', 'label' => __('Reçu le'), 'sortable' => true, 'searchable' => false],
            // Virtual column: the target lives in one of two foreign keys,
            // so it is neither sortable nor searchable in SQL.
            ['key' => 'target', 'label' => __('Contenu'), 'sortable' => false, 'searchable' => false],
            ['key' => 'reason', 'label' => __('Motif'), 'sortable' => true, 'searchable' => true],
            ['key' => 'locale', 'label' => __('Langue'), 'sortable' => true, 'searchable' => false],
            ['key' => 'reporter_email', 'label' => __('Signalant'), 'sortable' => false, 'searchable' => true],
            ['key' => 'status', 'label' => __('État'), 'sortable' => true, 'searchable' => false],
        ];
    }

    /**
     * The target is not a column of the table: it is one of two foreign
     * keys, and the list is read for it.
     */
    public function formatCell(Model $row, string $key): string
    {
        if ($key === 'target' && $row instanceof ContentReport) {
            return $row->targetLabel();
        }

        return parent::formatCell($row, $key);
    }

    public function actions(): array
    {
        return [
            ['label' => __('Traiter'), 'method' => 'openAct'],
        ];
    }

    public function panelView(): ?string
    {
        return 'livewire.admin.partials.report-act';
    }

    /**
     * The report the open panel works on, for the panel to show it whole:
     * a moderator decides on the description, not on the reason alone.
     */
    public function actTarget(): ?ContentReport
    {
        if ($this->actReportId === null) {
            return null;
        }

        return ContentReport::query()->with(['article', 'project'])->find($this->actReportId);
    }

    public function openAct(int $reportId): void
    {
        $report = ContentReport::query()->find($reportId);

        $this->actReportId = $reportId;
        // A sheet has no act on this screen: its ownership circuit is a
        // human one (SPEC 9.5), so the only outcomes offered are closing
        // the report one way or the other.
        $this->actKind = $report?->article_id !== null ? 'hide' : 'dismiss';
        $this->actMotive = '';
        $this->actRule = '';
        $this->actConflict = false;
        $this->actLegal = false;
        $this->resetErrorBag();
    }

    public function closeAct(): void
    {
        $this->actReportId = null;
        $this->resetErrorBag();
    }

    /**
     * Apply the chosen outcome: a moderation act on the article plus the
     * closing of the report, or the closing alone.
     */
    public function applyAct(ModerationService $moderation, ReportService $reports): void
    {
        /** @var User|null $actor */
        $actor = auth('web')->user();

        abort_unless($actor !== null && ($actor->isModerator() || $actor->is_super_admin), 403);

        $this->validate([
            'actKind' => ['required', 'in:hide,delete,dismiss,actioned'],
            'actMotive' => ['required', 'string', 'min:10', 'max:2000'],
            // Only the acts that touch a content need a rule: closing a
            // report is not a sanction, and demanding a numbered rule for
            // it would produce invented references (SPEC 9.2).
            'actRule' => [in_array($this->actKind, ['hide', 'delete'], true) ? 'required' : 'nullable', 'string', 'max:20'],
        ]);

        $report = ContentReport::query()->findOrFail($this->actReportId);
        $article = $report->article;

        if (in_array($this->actKind, ['hide', 'delete'], true) && $article === null) {
            $this->addError('actKind', __('Ce signalement ne porte pas sur un article : seule la clôture est possible ici.'));

            return;
        }

        try {
            // The guard above returned on a missing article for exactly
            // these two acts, so the target is there.
            if ($this->actKind === 'hide') {
                $moderation->hideArticle($article, $actor, $this->actMotive, $this->actRule, $this->actConflict, $this->actLegal);
            }

            if ($this->actKind === 'delete') {
                $moderation->deleteArticle($article, $actor, $this->actMotive, $this->actRule, $this->actConflict, $this->actLegal);
            }

            if ($this->actKind === 'dismiss') {
                $reports->dismiss($report, $actor, $this->actMotive);
            } else {
                $reports->markActioned($report, $actor, $this->actMotive);
            }
        } catch (ModerationException|ArticleException|ReportException $e) {
            Log::warning('ReportList: report handling refused', [
                'report_id' => $this->actReportId,
                'kind' => $this->actKind,
                'reason' => $e->getMessage(),
            ]);

            $this->addError('actMotive', $e->getMessage());

            return;
        }

        $this->actReportId = null;
        $this->dispatch('notify', message: $this->actKind === 'dismiss'
            ? __('Signalement classé sans suite.')
            : __('Signalement traité, acte journalisé.'));
    }

    /**
     * Open reports on the same target, shown in the panel: ten reports on
     * one article say something a single one does not.
     */
    public function siblingCount(ContentReport $report): int
    {
        $query = ContentReport::query()->open()->whereKeyNot($report->getKey());

        if ($report->article_id !== null) {
            $query->where('article_id', $report->article_id);
        } else {
            $query->where('project_id', $report->project_id);
        }

        return $query->count();
    }

    /**
     * Whether the closed reports are folded in, for the panel's toggle.
     */
    public function handledLabel(): string
    {
        return $this->showHandled
            ? __('Masquer les signalements traités')
            : __('Voir aussi les signalements traités');
    }

    /**
     * Statuses spelled out in the toggle line, so the screen says what it
     * is showing.
     */
    public function openCount(): int
    {
        return ContentReport::query()->where('status', ReportStatus::OPEN->value)->count();
    }
}

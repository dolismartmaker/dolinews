<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Moderation;

use App\Domain\Dolinews\Enums\ReportReason;
use App\Domain\Dolinews\Enums\ReportStatus;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\ContentReport;
use App\Domain\Dolinews\Models\Project;
use App\Models\User;
use App\Notifications\ContentReported;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reporting a published content to the moderation team (SPEC 9.9).
 *
 * Two rules shape this service:
 *
 *  - the report reaches the team by mail, because the team does not sit
 *    in the back-office waiting. A content validated too fast stays
 *    online until somebody is told, and a queue nobody watches is not
 *    being told;
 *  - the mail goes out on the FIRST open report of a target only. The
 *    following ones pile up in the queue silently until the team
 *    decides. Without that, one article is all it takes to mail every
 *    moderator as many times as a crowd can click, and the channel that
 *    was meant to warn them becomes the one they filter out.
 *
 * Handling a report is not itself a moderation act: hiding, withdrawing
 * or sanctioning goes through ModerationService, which journals the rule
 * and the motive (SPEC 9.4). What is recorded here is only the fate of
 * the report.
 */
class ReportService
{
    /**
     * File a report on a published article.
     *
     * @param  array{reason: ReportReason, body: string, email: string, locale: string}  $payload
     */
    public function reportArticle(Article $article, array $payload, ?User $reporter = null): ContentReport
    {
        return $this->file(['article_id' => $article->getKey()], $payload, $reporter);
    }

    /**
     * File a report on a project sheet: impersonation of a sheet is a
     * case of its own (SPEC 9.5), and it is not visible on any article.
     *
     * @param  array{reason: ReportReason, body: string, email: string, locale: string}  $payload
     */
    public function reportProject(Project $project, array $payload, ?User $reporter = null): ContentReport
    {
        return $this->file(['project_id' => $project->getKey()], $payload, $reporter);
    }

    /**
     * Close a report after a moderation act was taken on its target.
     */
    public function markActioned(ContentReport $report, User $moderator, string $resolution): ContentReport
    {
        return $this->close($report, $moderator, $resolution, ReportStatus::ACTIONED);
    }

    /**
     * Close a report the team read and decided nothing was due on.
     */
    public function dismiss(ContentReport $report, User $moderator, string $resolution): ContentReport
    {
        return $this->close($report, $moderator, $resolution, ReportStatus::DISMISSED);
    }

    /**
     * Reports awaiting a decision, for the dashboard tile.
     */
    public function openCount(): int
    {
        return ContentReport::query()->open()->count();
    }

    /**
     * Write the report, then notify the team once per target.
     *
     * @param  array{article_id?: int, project_id?: int}  $target
     * @param  array{reason: ReportReason, body: string, email: string, locale: string}  $payload
     */
    private function file(array $target, array $payload, ?User $reporter): ContentReport
    {
        /** @var array{0: ContentReport, 1: bool} $written */
        $written = DB::transaction(function () use ($target, $payload, $reporter): array {
            // Counted inside the transaction and BEFORE the insert: two
            // reports filed in the same second would otherwise both read
            // zero and both mail the team.
            $alreadyOpen = ContentReport::query()
                ->open()
                ->where(function ($query) use ($target): void {
                    foreach ($target as $column => $value) {
                        $query->where($column, $value);
                    }
                })
                ->lockForUpdate()
                ->count();

            $report = new ContentReport;
            $report->fill(array_merge($target, [
                'reason' => $payload['reason'],
                'body' => $payload['body'],
                'reporter_email' => $payload['email'],
                'reporter_user_id' => $reporter?->getKey(),
                'locale' => $payload['locale'],
                'status' => ReportStatus::OPEN,
            ]));
            $report->save();

            Log::info('ReportService: content reported', [
                'report_id' => $report->getKey(),
                'target' => $target,
                'reason' => $payload['reason']->value,
                'already_open' => $alreadyOpen,
            ]);

            return [$report, $alreadyOpen === 0];
        });

        [$report, $isFirst] = $written;

        if (! $isFirst) {
            return $report;
        }

        // After the commit, like every notification triggered by a write:
        // a rollback must not leave the team warned of a report that was
        // never recorded.
        DB::afterCommit(static function () use ($report): void {
            User::query()
                ->reviewTeam()
                ->get()
                ->each(fn (User $moderator) => $moderator->notify(new ContentReported($report)));
        });

        return $report;
    }

    /**
     * Shared closing path of both outcomes.
     */
    private function close(
        ContentReport $report,
        User $moderator,
        string $resolution,
        ReportStatus $status,
    ): ContentReport {
        if ($report->status !== ReportStatus::OPEN) {
            Log::warning('ReportService: closing refused, report already closed', [
                'report_id' => $report->getKey(),
                'status' => $report->status->value,
            ]);

            throw new ReportException(__('Ce signalement a déjà été traité.'));
        }

        if (trim($resolution) === '') {
            Log::warning('ReportService: closing refused, empty resolution', [
                'report_id' => $report->getKey(),
            ]);

            throw new ReportException(__('Le traitement d\'un signalement demande un motif.'));
        }

        $report->status = $status;
        $report->handled_by_user_id = $moderator->getKey();
        $report->handled_at = now();
        $report->resolution = $resolution;
        $report->save();

        Log::info('ReportService: report closed', [
            'report_id' => $report->getKey(),
            'status' => $status->value,
            'moderator' => $moderator->getKey(),
        ]);

        return $report;
    }
}

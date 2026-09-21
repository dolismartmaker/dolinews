<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\ReviewDecision;
use App\Domain\Dolinews\Models\Article;
use App\Models\User;
use App\Notifications\ReviewReminder;
use Illuminate\Console\Command;

/**
 * The automatic reminder after three idle days (SPEC 5.1): to the team
 * for a pending article, to the author for requested changes without
 * resubmission. Internal mechanism, never a public commitment.
 */
class ReviewRemindersCommand extends Command
{
    protected $signature = 'dolinews:review-reminders';

    protected $description = 'Send the three-day idle review reminders';

    public function handle(): int
    {
        $days = max(1, (int) config('dolinews.review.reminder_days', 3));
        $deadline = now()->subDays($days);

        // Pending articles with no thread activity since submission:
        // nudge the team.
        $pending = Article::query()
            ->where('status', ArticleStatus::PENDING->value)
            ->whereNull('deleted_at')
            ->where('submitted_at', '<', $deadline)
            ->get();

        foreach ($pending as $article) {
            $lastActivity = $article->reviewMessages()->max('created_at');

            if ($lastActivity !== null && $lastActivity->greaterThan($article->submitted_at)) {
                continue;
            }

            User::query()
                ->reviewTeam()
                ->get()
                ->each(fn (User $moderator) => $moderator->notify(
                    new ReviewReminder($article, 'team'),
                ));
        }

        // Requested changes without author rework: nudge the author.
        $awaited = Article::query()
            ->where('status', ArticleStatus::PENDING->value)
            ->whereNull('deleted_at')
            ->where('submitted_at', '<', $deadline)
            ->get();

        foreach ($awaited as $article) {
            $changes = $article->reviewMessages()
                ->where('decision', ReviewDecision::CHANGES_REQUESTED->value)
                ->where('created_at', '<', $deadline)
                ->exists();

            if (! $changes) {
                continue;
            }

            // Only when the author did not resubmit since the request:
            // a resubmission moves submitted_at forward.
            /** @var User|null $author */
            $author = User::query()->find($article->author_user_id);

            $author?->notify(new ReviewReminder($article, 'author'));
        }

        $this->info('Review reminders dispatched.');

        return self::SUCCESS;
    }
}

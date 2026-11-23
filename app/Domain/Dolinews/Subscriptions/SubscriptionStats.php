<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Subscriptions;

use App\Domain\Dolinews\Enums\EmailDigest;
use App\Domain\Dolinews\Models\EditorWatch;
use App\Domain\Dolinews\Models\ProjectWatch;
use App\Domain\Dolinews\Models\SubscriptionLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who follows what, counted for the back-office (SPEC 6.4).
 *
 * Only accounts are counted: the generic feeds are read without one and
 * leave nothing behind. A personal feed is counted by its token, which
 * says it was issued, not that a reader still fetches it; logging every
 * fetch to know would be new personal data (SPEC 9.8) for a figure.
 *
 * Suspended accounts receive nothing, so they follow nothing here.
 *
 * The totals are for the whole team. The count per sheet and per editor
 * is for the super admin alone: the team is recruited among editors, and
 * how many readers a competitor has is commercial information that a
 * moderator has no use for in the mandate (SPEC 9.1).
 */
class SubscriptionStats
{
    /**
     * Whether the viewer may read the follower count of a sheet or an
     * editor.
     */
    public function canSeeFollowersPerTarget(?User $viewer): bool
    {
        return $viewer !== null && $viewer->is_super_admin;
    }

    /**
     * Correlated count of the active accounts following the project of
     * the outer row, to select as a column of a `projects` query.
     */
    public function projectFollowersSubquery(): QueryBuilder
    {
        return DB::table('project_watches')
            ->join('users', 'users.id', '=', 'project_watches.user_id')
            ->selectRaw('count(*)')
            ->whereColumn('project_watches.project_id', 'projects.id')
            ->where('users.active', true);
    }

    /**
     * Correlated count of the active accounts following the editor of
     * the outer row, to select as a column of an `editors` query.
     */
    public function editorFollowersSubquery(): QueryBuilder
    {
        return DB::table('editor_watches')
            ->join('users', 'users.id', '=', 'editor_watches.user_id')
            ->selectRaw('count(*)')
            ->whereColumn('editor_watches.editor_id', 'editors.id')
            ->where('users.active', true);
    }

    /**
     * Active accounts following at least one thing: the whole feed, the
     * security announcements, a sheet or an editor.
     */
    public function subscribedAccounts(): int
    {
        return $this->activeAccounts()
            ->where(static function (Builder $query): void {
                $query->where('watches_all', true)
                    ->orWhere('watches_all_security', true)
                    ->orWhereHas('projectWatches')
                    ->orWhereHas('editorWatches');
            })
            ->count();
    }

    public function wholeFeedFollowers(): int
    {
        return $this->activeAccounts()->where('watches_all', true)->count();
    }

    public function securityFollowers(): int
    {
        return $this->activeAccounts()->where('watches_all_security', true)->count();
    }

    /**
     * Project watches held by active accounts, one per account and sheet.
     */
    public function projectWatches(): int
    {
        return ProjectWatch::query()
            ->whereRelation('user', 'active', true)
            ->count();
    }

    /**
     * Editor watches held by active accounts, one per account and editor.
     */
    public function editorWatches(): int
    {
        return EditorWatch::query()
            ->whereRelation('user', 'active', true)
            ->count();
    }

    /**
     * Accounts the digest sweep would mail, per sending cadence. Same
     * condition as the sweep itself (User::scopeSubscribedTo), so the
     * figure is the number of addresses the mails actually go to.
     *
     * @return array<string, int>
     */
    public function emailRecipientsByCadence(): array
    {
        $counts = [];

        foreach (EmailDigest::sending() as $cadence) {
            $counts[$cadence->value] = User::query()->subscribedTo($cadence)->count();
        }

        return $counts;
    }

    /**
     * Personal feed tokens held by active accounts. Issued, not read.
     */
    public function personalFeeds(): int
    {
        return $this->activeAccounts()->whereNotNull('feed_token')->count();
    }

    /**
     * Addresses typed in the public form whose confirmation link is still
     * open: subscriptions that may yet exist, and are not counted above.
     */
    public function pendingConfirmations(): int
    {
        return SubscriptionLink::query()
            ->where('purpose', SubscriptionLink::PURPOSE_CONFIRM)
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->distinct()
            ->count('email');
    }

    /**
     * @return Builder<User>
     */
    private function activeAccounts(): Builder
    {
        return User::query()->where('active', true);
    }
}

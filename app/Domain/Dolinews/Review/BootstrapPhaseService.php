<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Review;

use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\ServiceState;
use App\Models\User;

/**
 * The bootstrap phase (SPEC 5.1): an empty feed demonstrates nothing, so
 * the super admin publishes the first articles without quorum, including
 * their own, until the phase closes.
 *
 * Four bounds, because a derogation without a counter never closes:
 * ceiling of ten bootstrap publications; automatic end at the earlier of
 * the first third-party submission or the team reaching the floor of six
 * moderators; a public durable mention on those articles; identical
 * logging. The phase never reopens, whatever the team's later state:
 * the closure is persisted in service_state.
 */
class BootstrapPhaseService
{
    private const CLOSED_KEY = 'bootstrap_phase_closed_at';

    /**
     * Whether the bootstrap phase is currently open.
     *
     * Open while: never closed, fewer than the ceiling of bootstrap
     * publications, no third-party submission ever, and the team below
     * the moderator floor. The first detected closure is persisted:
     * later facts (team dropping under the floor) never reopen it.
     */
    public function isOpen(): bool
    {
        if (ServiceState::read(self::CLOSED_KEY) !== null) {
            return false;
        }

        if ($this->bootstrapPublications() >= $this->ceiling()) {
            $this->close('ceiling');

            return false;
        }

        if ($this->hasThirdPartySubmission()) {
            $this->close('third_party_submission');

            return false;
        }

        if ($this->activeModerators() >= $this->floor()) {
            $this->close('team_floor');

            return false;
        }

        return true;
    }

    /**
     * Record a bootstrap publication and close the phase when the
     * ceiling is now reached.
     */
    public function recordPublication(): void
    {
        if ($this->bootstrapPublications() >= $this->ceiling()) {
            $this->close('ceiling');
        }
    }

    /**
     * Number of articles published through the bootstrap route so far.
     */
    public function bootstrapPublications(): int
    {
        return Article::query()
            ->where('publication_mode', 'bootstrap')
            ->count();
    }

    /**
     * Active moderators: is_moderator and active (SPEC 9.1 floor).
     */
    public function activeModerators(): int
    {
        return User::query()
            ->where('is_moderator', true)
            ->where('active', true)
            ->count();
    }

    /**
     * Whether any submission ever came from an account other than the
     * super admin(s): that alone closes the phase (SPEC 5.1).
     */
    public function hasThirdPartySubmission(): bool
    {
        return Article::query()
            ->whereNotNull('submitted_at')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('users')
                    ->whereColumn('users.id', 'articles.author_user_id')
                    ->where('users.is_super_admin', false);
            })
            ->exists();
    }

    /**
     * Persist the closure with its triggering reason. One-way by
     * design: writeOnce never overwrites.
     */
    private function close(string $reason): void
    {
        ServiceState::writeOnce(
            self::CLOSED_KEY,
            now()->format('Y-m-d H:i:s').' ('.$reason.')',
        );
    }

    /**
     * The bootstrap publication ceiling (SPEC 5.1: ten).
     */
    private function ceiling(): int
    {
        return max(0, (int) config('dolinews.review.bootstrap_ceiling', 10));
    }

    /**
     * The moderator floor closing the phase (SPEC 9.1: six).
     */
    private function floor(): int
    {
        return max(1, (int) config('dolinews.review.moderator_floor', 6));
    }
}

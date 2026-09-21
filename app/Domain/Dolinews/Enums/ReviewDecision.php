<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * Decision posted in a review thread (SPEC 4.5/5.1).
 *
 * accepted: a moderator's approval towards the quorum. rejected: refusal,
 * motive and rule in the thread, resubmission possible. changes_requested:
 * the author must rework and resubmit.
 */
enum ReviewDecision: string
{
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case CHANGES_REQUESTED = 'changes_requested';

    /**
     * French label shown in the interface and the circuit emails.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACCEPTED => 'accepté',
            self::REJECTED => 'refusé',
            self::CHANGES_REQUESTED => 'modifications demandées',
        };
    }
}

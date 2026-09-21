<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * Moderation acts logged in moderation_log (SPEC 4.5/9).
 *
 * Reversal pairs: hidden/unhidden, deleted/restored. warned and suspended
 * target accounts. proof_revoked revokes a contribution proof. claimed and
 * transferred cover the project-sheet ownership circuit (SPEC 9.5).
 * published_by_admin is the quorum override (SPEC 5.1).
 */
enum ModerationAction: string
{
    case HIDDEN = 'hidden';
    case UNHIDDEN = 'unhidden';
    case DELETED = 'deleted';
    case RESTORED = 'restored';
    case WARNED = 'warned';
    case SUSPENDED = 'suspended';
    case PROOF_REVOKED = 'proof_revoked';
    case TRANSFERRED = 'transferred';
    case CLAIMED = 'claimed';
    case PUBLISHED_BY_ADMIN = 'published_by_admin';

    /**
     * The act that undoes this one when an automatic cancellation lifts
     * the effect of an unconfirmed conflict-of-interest act (SPEC 9.6).
     *
     * The reversal uses the enum's own vocabulary: a suspension is lifted
     * by a restored entry (the account is restored), the spec's closed
     * action set has no dedicated "unsuspended" value.
     */
    public function reversal(): ?self
    {
        return match ($this) {
            self::HIDDEN => self::UNHIDDEN,
            self::DELETED => self::RESTORED,
            self::SUSPENDED => self::RESTORED,
            self::PROOF_REVOKED => null, // proof re-grant is a fresh proof, not a reversal
            default => null,
        };
    }
}

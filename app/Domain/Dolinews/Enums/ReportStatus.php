<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * Where a report stands in the team's queue.
 *
 * Two ways out, kept apart on purpose: ACTIONED says a moderation act
 * followed, and that act carries its own entry in moderation_log with
 * the invoked rule (SPEC 9.4); DISMISSED says the team read it and
 * decided nothing was due. Merging the two into a single "closed" would
 * lose exactly what a contested moderation needs to show.
 */
enum ReportStatus: string
{
    case OPEN = 'open';
    case ACTIONED = 'actioned';
    case DISMISSED = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => __('ouvert'),
            self::ACTIONED => __('suivi d\'un acte'),
            self::DISMISSED => __('classé sans suite'),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * Visibility of a review-thread message (SPEC 4.5/5.5).
 *
 * author: read by the author and the team. moderators: internal
 * deliberation only. A message carrying a decision is always author.
 */
enum ReviewVisibility: string
{
    case AUTHOR = 'author';
    case MODERATORS = 'moderators';
}

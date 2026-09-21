<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * Nature of a dated feed entry (SPEC 4.3).
 *
 * release: a project version. announcement: an editor-level announcement
 * that may live without a project.
 */
enum ArticleType: string
{
    case RELEASE = 'release';
    case ANNOUNCEMENT = 'announcement';
}

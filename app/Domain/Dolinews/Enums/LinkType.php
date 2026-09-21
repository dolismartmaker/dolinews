<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * Typed links of a project sheet (SPEC 4.2).
 */
enum LinkType: string
{
    case DOLISTORE = 'dolistore';
    case SHOP = 'shop';
    case DEMO = 'demo';
    case DOC = 'doc';
    case REPO = 'repo';
    case SUPPORT = 'support';
    case OTHER = 'other';
}

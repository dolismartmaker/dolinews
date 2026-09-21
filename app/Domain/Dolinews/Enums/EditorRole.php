<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * Role of an account inside an editor (SPEC 4.1).
 */
enum EditorRole: string
{
    case OWNER = 'owner';
    case MEMBER = 'member';
}

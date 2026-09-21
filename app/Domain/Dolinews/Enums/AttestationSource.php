<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * Origin of a test attestation (SPEC 4.4/10).
 */
enum AttestationSource: string
{
    case CAPTESTS = 'captests';
    case CI = 'ci';
    case OTHER = 'other';
}

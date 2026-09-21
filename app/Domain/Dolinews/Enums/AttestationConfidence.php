<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * Confidence of a test attestation (SPEC 4.4/10).
 *
 * Only self_declared exists at launch: the instances are hosted by the
 * editors themselves, so the indicator is declarative whatever it says.
 * The enum exists from day one so third_party_verified lands without a
 * migration when a neutral instance exists.
 */
enum AttestationConfidence: string
{
    case SELF_DECLARED = 'self_declared';
    case THIRD_PARTY_VERIFIED = 'third_party_verified';
}

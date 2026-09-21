<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * How a contribution proof was established (SPEC 3.2/3.3).
 *
 * email: possession of the commit address proven by a one-time code.
 * gpg: a challenge signed with the key that signed the commits.
 * manual: validation by the moderation team, for editors without a public
 * repository (SPEC 3.3). Not an automated method: the enum of SPEC 4.1
 * lists the two automated ones, SPEC 3.3 requires the human path anyway.
 */
enum ProofMethod: string
{
    case EMAIL = 'email';
    case GPG = 'gpg';
    case MANUAL = 'manual';
}

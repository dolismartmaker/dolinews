<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Contributors;

use RuntimeException;

/**
 * Contributor qualification failure (SPEC 3): unknown address, wrong or
 * exhausted code, signature rejected, or address already bound to
 * another account (SPEC 3.4).
 */
class ContributorVerificationException extends RuntimeException {}

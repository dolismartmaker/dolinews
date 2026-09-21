<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Articles;

use RuntimeException;

/**
 * Publication quota refusal (SPEC 5.3): bucket empty or queue ceiling
 * reached. Both aim at good-faith noise, never at a determined actor.
 */
class QuotaException extends RuntimeException {}

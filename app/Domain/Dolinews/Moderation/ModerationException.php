<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Moderation;

use RuntimeException;

/**
 * Moderation act failure: missing motive, wrong confirmation, or an act
 * not applicable to its target.
 */
class ModerationException extends RuntimeException {}

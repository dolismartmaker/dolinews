<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Media;

use RuntimeException;

/**
 * Media intake refusal (SPEC 7): too large, SVG, or undecodable.
 */
class MediaException extends RuntimeException {}

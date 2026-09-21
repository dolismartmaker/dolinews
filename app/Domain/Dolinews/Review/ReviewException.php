<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Review;

use RuntimeException;

/**
 * Review circuit failure: unauthorized thread post or refused quorum
 * override.
 */
class ReviewException extends RuntimeException {}

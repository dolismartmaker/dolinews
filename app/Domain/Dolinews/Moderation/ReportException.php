<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Moderation;

use RuntimeException;

/**
 * A report act refused: closing a report already closed, mostly, which
 * is what two moderators opening the same queue produce.
 */
class ReportException extends RuntimeException {}

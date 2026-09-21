<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Articles;

use RuntimeException;

/**
 * Article lifecycle failure: ownership, status or quota violation.
 */
class ArticleException extends RuntimeException {}

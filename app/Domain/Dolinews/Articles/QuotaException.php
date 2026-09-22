<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Articles;

use RuntimeException;

/**
 * Publication quota refusal (SPEC 5.3): bucket empty or queue ceiling
 * reached. Both aim at good-faith noise, never at a determined actor.
 *
 * Which of the two mattered is carried here rather than deduced from
 * the message: the API answers a distinct code for each (SPEC 5.2), and
 * an operator told "bucket empty" when their queue is full goes and
 * raises the wrong setting. Reading a French substring to decide would
 * put the wire contract at the mercy of a wording change.
 */
class QuotaException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly bool $queueCeiling,
    ) {
        parent::__construct($message);
    }

    /**
     * The project (or the editor, for announcements without a project)
     * has no publication token left.
     */
    public static function bucketEmpty(string $message): self
    {
        return new self($message, false);
    }

    /**
     * The editor already has as many articles in review as the ceiling
     * allows.
     */
    public static function queueCeilingReached(string $message): self
    {
        return new self($message, true);
    }
}

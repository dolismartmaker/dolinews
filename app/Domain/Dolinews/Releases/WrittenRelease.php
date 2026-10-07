<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Releases;

/**
 * The text of an announcement, whoever wrote it (SPEC 5.8).
 *
 * Three fields and no metadata: the focus, the Dolibarr range and the
 * maturity are derived from the release itself by ReleaseWatchService,
 * never taken from a writer. What a writer produces is prose, and prose
 * is all it is trusted with.
 */
class WrittenRelease
{
    public function __construct(
        public readonly string $title,
        public readonly string $summary,
        public readonly string $body,
    ) {}
}

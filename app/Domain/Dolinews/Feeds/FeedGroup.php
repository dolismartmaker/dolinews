<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Feeds;

use App\Domain\Dolinews\Models\Article;

/**
 * One entry of the feed page: an announcement, and the ones that folded
 * under it (SPEC 6.1).
 *
 * Most groups hold a lead and nothing else, which is a feed read one
 * announcement at a time. A group with followers is a burst by one
 * project: four fixes in an afternoon, a catalogue deposited in one run.
 */
class FeedGroup
{
    /**
     * @param  list<Article>  $others  folded under the lead, newest first
     */
    public function __construct(
        public readonly Article $lead,
        public readonly array $others = [],
    ) {}

    /**
     * Whether anything folded under the lead.
     */
    public function isCollapsed(): bool
    {
        return $this->others !== [];
    }

    /**
     * How many announcements folded.
     */
    public function foldedCount(): int
    {
        return count($this->others);
    }

    /**
     * The oldest folded announcement, which dates the other end of the
     * burst: the reader is told the range it covers, never just a count.
     */
    public function oldest(): ?Article
    {
        if ($this->others === []) {
            return null;
        }

        return $this->others[count($this->others) - 1];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Releases;

use App\Domain\Dolinews\Enums\Focus;
use Illuminate\Support\Carbon;

/**
 * One release of the Dolibarr core, as the public release feed states it
 * (SPEC 5.8).
 *
 * Read-only on purpose: what comes out of a third-party feed is evidence,
 * not state. Everything the service decides from it - the focus, the
 * announced majors - is derived here rather than stored, so a feed that
 * changes its wording cannot leave stale decisions behind in the
 * database.
 */
class DolibarrRelease
{
    public function __construct(
        public readonly string $tag,
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
        public readonly Carbon $releasedAt,
        public readonly string $url,
        public readonly string $changelog,
    ) {}

    /**
     * Build a release from a feed entry, or null when the tag is not a
     * plain stable version.
     *
     * The filter lives in the parser because it is the tag itself that
     * carries the information: Dolibarr suffixes its pre-releases
     * (21.0.0-beta, 20.0.0-rc1), and nothing else distinguishes them in
     * the feed. An integrator does not deploy a beta, so an article about
     * one is noise in a feed whose readers run production instances.
     */
    public static function fromTag(
        string $tag,
        Carbon $releasedAt,
        string $url,
        string $changelog,
    ): ?self {
        if (preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/', trim($tag), $m) !== 1) {
            return null;
        }

        return new self(
            trim($tag),
            (int) $m[1],
            (int) $m[2],
            (int) $m[3],
            $releasedAt,
            $url,
            $changelog,
        );
    }

    /**
     * The version as the announcement states it, without the leading v
     * some tags carry.
     */
    public function version(): string
    {
        return $this->major.'.'.$this->minor.'.'.$this->patch;
    }

    /**
     * The focus of the announcement, derived here and never taken from
     * the writer (SPEC 5.8).
     *
     * A focus is not a wording: `security` puts the article at the head
     * of the review queue and mails every subscriber who asked for
     * security fixes only (SPEC 6.4). Letting a text generator set it
     * would make those mails depend on how a changelog happened to be
     * phrased, in both directions - a missed security release nobody is
     * told about, and a routine one that wakes every integrator up.
     *
     * So: the security clue is looked for in the changelog itself, and
     * it always wins. Failing that, the version number decides, which is
     * how Dolibarr numbers its own releases - x.y.0 opens a branch, the
     * rest fixes it.
     */
    public function focus(): Focus
    {
        if ($this->mentionsSecurity()) {
            return Focus::SECURITY;
        }

        return $this->patch === 0 ? Focus::FEATURE_MAJOR : Focus::BUGFIX_MINOR;
    }

    /**
     * Whether the changelog states a security fix.
     *
     * Deliberately wide: a false positive costs a reviewer one look at an
     * article already in front of them, a false negative costs an
     * integrator the mail that told them to patch.
     */
    public function mentionsSecurity(): bool
    {
        return preg_match(
            '/\b(cve-\d{4}-\d+|security|vulnerab|xss|csrf|sql injection|faille|s[eé]curit[eé])/i',
            $this->changelog,
        ) === 1;
    }

    /**
     * The CVE identifiers the changelog names, deduplicated and
     * uppercased. Empty on a release that names none.
     *
     * @return array<int, string>
     */
    public function cveIdentifiers(): array
    {
        if (preg_match_all('/\bCVE-\d{4}-\d{4,7}\b/i', $this->changelog, $m) !== false) {
            /** @var array<int, string> $found */
            $found = $m[0];

            return array_values(array_unique(array_map('strtoupper', $found)));
        }

        return [];
    }
}

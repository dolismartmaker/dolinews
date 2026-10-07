<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Releases;

/**
 * Turns a release note into the text of an announcement (SPEC 5.8).
 *
 * An interface for the reason TranslationEngine is one: a deployment
 * must be able to serve this with something else, self-hosted included,
 * and a service the ecosystem relies on cannot die with a supplier.
 *
 * What an implementation is trusted with stops at the prose. It never
 * decides the focus, the announced Dolibarr majors or the maturity:
 * those drive the review queue and the security mails, and they are
 * derived from the release itself (see DolibarrRelease::focus()).
 */
interface ReleaseWriter
{
    /**
     * Whether the writer is configured and usable.
     *
     * An instance with no writing endpoint configured is a normal state,
     * not an error: the watch then falls back to the mechanical writer,
     * which states the facts and links to the release note.
     */
    public function isAvailable(): bool;

    /**
     * Write the announcement, or return null when the text could not be
     * produced.
     *
     * Null rather than an exception: the caller has a usable fallback,
     * and a release that cannot be written about nicely is still a
     * release integrators must be told about.
     *
     * @param  string  $locale  content locale, e.g. fr_FR
     */
    public function write(DolibarrRelease $release, string $locale): ?WrittenRelease;
}

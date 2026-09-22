<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Translation;

/**
 * A machine translation engine (SPEC 5.7).
 *
 * An interface rather than a call to one provider, for the same reason
 * D4 keeps the contributor check on a local git clone rather than on a
 * forge API: a service that dies the day a supplier closes or changes
 * its prices is not a service the ecosystem can rely on. A
 * self-hostable engine must stay a viable option.
 */
interface TranslationEngine
{
    /**
     * Whether the engine is configured and usable.
     *
     * An instance with no engine configured is a normal state, not an
     * error: automatic translation is then simply not offered.
     */
    public function isAvailable(): bool;

    /**
     * Translate a batch of texts, or return null when the engine could
     * not deliver the whole batch.
     *
     * A batch and not a single text: the title, the summary and the
     * blocks of a body leave in one call instead of a dozen, and an
     * engine that bills per call or caches per segment works far better
     * that way. The answer keeps the order of the input, so the caller
     * can put a body back together.
     *
     * All or nothing on the batch: half a translated announcement is
     * worse than none, since the feed would present it as the reading of
     * the announcement in that language.
     *
     * Markdown must come out as Markdown: the body of an announcement
     * carries headings, lists and links, and an engine that flattens
     * them produces a text nobody can publish.
     *
     * @param  array<int, string>  $texts
     * @param  string  $sourceLocale  BCP-47 style, e.g. fr_FR
     * @param  string  $targetLocale  BCP-47 style, e.g. es_ES
     * @return array<int, string>|null in the order of $texts
     */
    public function translateBatch(array $texts, string $sourceLocale, string $targetLocale): ?array;

    /**
     * The locales this engine can translate into, out of the service's
     * own content locales. An empty list means it says nothing about
     * them, and the caller may try any.
     *
     * @return array<int, string>
     */
    public function supportedLocales(): array;
}

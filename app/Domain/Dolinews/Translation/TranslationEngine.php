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
     * Translate one text, or return null when the engine could not.
     *
     * Markdown must come out as Markdown: the body of an announcement
     * carries headings, lists and links, and an engine that flattens
     * them produces a text nobody can publish.
     *
     * @param  string  $sourceLocale  BCP-47 style, e.g. fr_FR
     * @param  string  $targetLocale  BCP-47 style, e.g. es_ES
     */
    public function translate(string $text, string $sourceLocale, string $targetLocale): ?string;

    /**
     * The locales this engine can translate into, out of the service's
     * own content locales. An empty list means it says nothing about
     * them, and the caller may try any.
     *
     * @return array<int, string>
     */
    public function supportedLocales(): array;
}

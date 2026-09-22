<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Translation;

/**
 * What every engine shares: the content locales of the service turned
 * into the two-letter codes engines expect, and the locale list a
 * deployment may narrow.
 */
trait TranslatesLocales
{
    /**
     * fr_FR -> FR. Every content locale of the service is a distinct
     * language, none being a regional variant of another, so the first
     * two letters identify it without ambiguity.
     */
    protected function engineLanguage(string $locale): string
    {
        return strtoupper(substr($locale, 0, 2));
    }

    /**
     * Locales the deployment declared its engine handles, empty meaning
     * it says nothing and the caller may try any.
     *
     * @return array<int, string>
     */
    protected function configuredLocales(): array
    {
        $configured = (array) config('dolinews.translation.locales', []);

        return array_values(array_filter(
            array_map(
                static fn (mixed $locale): string => is_string($locale) ? trim($locale) : '',
                $configured,
            ),
            static fn (string $locale): bool => $locale !== '',
        ));
    }
}

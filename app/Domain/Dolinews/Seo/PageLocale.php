<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Seo;

/**
 * The two forms a locale takes outside the application.
 *
 * Interface locales are two letters (`fr`), content locales are full
 * (`fr_FR`, SPEC 4.3), and the markup wants the BCP 47 spelling
 * (`fr-FR`). The mapping from one to the other is read from the
 * configured content locales rather than built by hand: the service
 * ships `pt_PT`, and a guess would have written `pt_BR` half the time.
 */
final class PageLocale
{
    /**
     * Language tag for a `lang` or `hreflang` attribute.
     */
    public static function tag(string $locale): string
    {
        return str_replace('_', '-', $locale);
    }

    /**
     * Full locale, as Open Graph expects it.
     */
    public static function full(string $locale): string
    {
        if (str_contains($locale, '_')) {
            return $locale;
        }

        $short = substr($locale, 0, 2);

        /** @var list<string> $locales */
        $locales = (array) config('dolinews.content_locales', []);

        foreach ($locales as $candidate) {
            if (str_starts_with($candidate, $short.'_')) {
                return $candidate;
            }
        }

        return $locale;
    }
}

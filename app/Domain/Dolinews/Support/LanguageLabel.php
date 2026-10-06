<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Support;

/**
 * The badge a text wears when it is NOT written in the language being
 * read (SPEC 6.1).
 *
 * What nobody translated is shown rather than hidden - hiding it would
 * be the diffusion penalty the spec refuses - so the reader has to be
 * told, before reading on, which language awaits them. Silence would be
 * a promise the page cannot keep.
 *
 * Shared by the announcements and by the project sheets, and read as is
 * by third-party sites through the feed: one wording, in ten languages,
 * decided in one place.
 */
final class LanguageLabel
{
    /**
     * @param  string  $contentLocale  language the text is written in (fr_FR)
     * @param  string  $readerLocale  language being read (fr or fr_FR)
     */
    public static function foreign(string $contentLocale, string $readerLocale): ?string
    {
        if (str_starts_with($contentLocale, substr($readerLocale, 0, 2))) {
            return null;
        }

        $short = substr($contentLocale, 0, 2);

        return trim(__('en').' '.(string) config(
            'dolinews.locale_names.'.$short,
            strtoupper($short),
        ));
    }
}

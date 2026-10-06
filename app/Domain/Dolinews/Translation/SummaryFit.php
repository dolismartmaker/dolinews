<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Translation;

/**
 * A translated summary cut down to what its column holds (SPEC 5.7).
 *
 * German and Polish run noticeably longer than French, so a summary
 * written just under the limit comes back over it. Left unchecked the
 * insert fails on the column width and takes the whole run down.
 *
 * A title is skipped rather than shortened, a clipped title reading as
 * a sentence broken off wherever it appears. A summary is a text
 * written to be skimmed, where an ellipsis is a convention rather than
 * a defect.
 *
 * Cut at the last whole sentence that fits, so what is left reads as it
 * was written. When no sentence boundary leaves enough of the text - a
 * single long sentence, or a first one of three words - the cut falls
 * on the last word instead, and says so with an ellipsis.
 *
 * Shared by the announcements, whose summary column holds 500, and by
 * the project sheets, whose own holds 255: one rule, two bounds.
 */
final class SummaryFit
{
    /**
     * Below this share of the limit, cutting at a sentence boundary
     * throws away too much of the summary to still be a summary.
     */
    private const MIN_KEPT_RATIO = 0.6;

    public static function fit(string $summary, int $limit): string
    {
        if (mb_strlen($summary) <= $limit) {
            return $summary;
        }

        $head = mb_substr($summary, 0, $limit);

        // Greedy on purpose: the LAST boundary that fits. A decimal in
        // "1.0.15" is not one, the dot not being followed by a space.
        if (preg_match('/^.*[.!?](?=\s|$)/su', $head, $matches) === 1) {
            $sentences = rtrim($matches[0]);

            if (mb_strlen($sentences) >= (int) round($limit * self::MIN_KEPT_RATIO)) {
                return $sentences;
            }
        }

        $word = mb_substr($head, 0, max(1, $limit - 3));
        $space = mb_strrpos($word, ' ');

        if ($space !== false) {
            $word = mb_substr($word, 0, $space);
        }

        return rtrim($word, " \t\n\r,;:-").'...';
    }
}

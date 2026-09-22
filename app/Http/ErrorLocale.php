<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\Request;

/**
 * The interface language of an error page.
 *
 * An address no route serves never goes through the web middleware
 * group, so SetLocale never runs and the page would answer in the
 * configured locale whoever asked. The address still carries the
 * language (SPEC 6.5): a reader who mistypes an announcement number
 * under /pl reads Polish everywhere else, and has no reason to land on
 * French here.
 *
 * Only the segment is read, never Accept-Language: negotiation is the
 * bare root's job (SPEC 11), and an error page is not an entry point.
 */
class ErrorLocale
{
    /**
     * Apply the language of the address, when it names one.
     *
     * Setting it fires LocaleUpdated, which refreshes the default
     * locale of route(): the links of the page then point at the
     * reader's language rather than at the configured one.
     */
    public static function apply(Request $request): void
    {
        /** @var list<string> $offered */
        $offered = array_values((array) config('dolinews.locales', ['fr']));

        $segment = $request->segment(1);

        if (is_string($segment) && in_array($segment, $offered, true)) {
            app()->setLocale($segment);
        }
    }
}

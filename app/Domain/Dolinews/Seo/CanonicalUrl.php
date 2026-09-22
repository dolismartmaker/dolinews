<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Seo;

use Illuminate\Http\Request;

/**
 * The address a page declares as its own.
 *
 * The feed is one page read through many filter combinations (SPEC
 * 6.1): `/?focus=security`, `/?dolibarr=22`, `/?lang=es` all render the
 * same feed narrowed down. Left to themselves, search engines index
 * them as that many near-identical pages and split what the feed is
 * worth between them. Every filtered view therefore points at the bare
 * feed.
 *
 * The page number is the one parameter kept: page two is not a variant
 * of page one, it carries other announcements, and pointing it at the
 * first page would drop everything below the fold of the fold.
 */
final class CanonicalUrl
{
    public static function for(Request $request): string
    {
        $page = (int) $request->query('page', 1);

        return $page > 1
            ? $request->url().'?page='.$page
            : $request->url();
    }
}

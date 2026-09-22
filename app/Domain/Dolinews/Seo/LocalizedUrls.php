<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Seo;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * The address of the page being read, in each of the languages the
 * service offers (SPEC 6.5).
 *
 * Two readers want this: a search engine, which needs the versions
 * declared to one another or it treats ten translations of one page as
 * ten competitors; and the visitor using the language switch, who
 * expects to stay where they are rather than land back on the feed.
 *
 * Both are served from the current route rather than from a rewritten
 * path: the parameters are already resolved, so a sheet keeps its slug
 * and an announcement its identifier.
 */
final class LocalizedUrls
{
    /**
     * Locale => address, empty when the current page carries no
     * language segment - the account and the back-office, where the
     * switch keeps working through the session.
     *
     * @return array<string, string>
     */
    public static function for(Request $request, bool $keepQuery = false): array
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return [];
        }

        $name = $route->getName();

        // Read from the pattern and not from the resolved parameters:
        // SetLocale drops the language one so that it never reaches a
        // controller signature, and the pattern says what the address
        // carries whether or not the value is still there.
        if ($name === null || ! str_starts_with($route->uri(), '{locale}')) {
            return [];
        }

        $parameters = $route->parameters();

        $query = $keepQuery
            ? $request->query()
            : CanonicalUrl::keptQuery($request);

        $urls = [];

        /** @var list<string> $locales */
        $locales = array_values((array) config('dolinews.locales', ['fr']));

        foreach ($locales as $locale) {
            $urls[$locale] = route($name, array_merge($parameters, $query, ['locale' => $locale]));
        }

        return $urls;
    }
}

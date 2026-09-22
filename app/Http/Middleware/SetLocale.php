<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the visitor's interface locale (D14).
 *
 * Two sources, in this order:
 *
 *  1. the choice made in the header switch, kept in the session by the
 *     /locale/{code} route. It always wins: someone who asked for a
 *     language means it, whatever their browser says;
 *  2. failing that, the browser's Accept-Language header.
 *
 * Without the second one the service opens in French for everyone, and
 * a Spanish reader has to find a language switch written in a language
 * they did not ask for before the site speaks theirs. The interface is
 * translated into nine languages precisely so that it does not have to
 * come to that.
 *
 * The negotiated locale is NOT written to the session: it is derived
 * again on every request, so the switch stays the only thing that
 * decides for good.
 */
class SetLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $offered */
        $offered = array_values((array) config('dolinews.locales', ['fr']));

        $chosen = $request->hasSession() ? $request->session()->get('locale') : null;

        $locale = is_string($chosen) && in_array($chosen, $offered, true)
            ? $chosen
            : $this->negotiated($request, $offered);

        app()->setLocale($locale);

        $response = $next($request);

        // Two visitors get two different documents from the same URL:
        // an intermediate cache that ignores this serves the first
        // one's language to everyone behind it.
        $response->setVary('Accept-Language', false);

        return $response;
    }

    /**
     * The best offered locale for this request's Accept-Language.
     *
     * Walked by hand rather than through getPreferredLanguage(), which
     * answers with the first offered locale when NOTHING matches: a
     * real match and a fallback then look alike, and the fallback has
     * to be the application's configured locale, not whichever language
     * happens to head the list.
     *
     * Language tags are cut to their two first letters, which is what
     * the offered set holds: a browser asking for pt-BR is served the
     * Portuguese interface rather than French.
     *
     * @param  list<string>  $offered
     */
    private function negotiated(Request $request, array $offered): string
    {
        // getLanguages() comes back ordered by quality value, so the
        // first match is the visitor's best offered language.
        foreach ($request->getLanguages() as $language) {
            $short = strtolower(substr(str_replace('_', '-', $language), 0, 2));

            if (in_array($short, $offered, true)) {
                return $short;
            }
        }

        return (string) config('app.locale', 'fr');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the visitor's interface locale (D14).
 *
 * Three sources, in this order:
 *
 *  1. the language segment of the address, on every page a reader is
 *     served (SPEC 6.5). It is the address itself, so nothing else can
 *     contradict it: two people opening the same link read the same
 *     page, which is the whole point of putting the language there;
 *  2. the choice made in the header switch, kept in the session, for
 *     the surfaces that carry no language segment - the account and the
 *     back-office;
 *  3. failing both, the browser's Accept-Language header.
 *
 * Without the third one the service opens in French for everyone, and
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

        $current = $request->route();
        $prefixed = $current instanceof Route && str_starts_with($current->uri(), '{locale}');
        $routed = $prefixed ? $current->parameter('locale') : null;
        $negotiated = false;

        // Read, then dropped from the route. A controller receives the
        // route parameters in their order of appearance, so a language
        // segment left in place arrives as the first argument of every
        // public action - and the announcement, the sheet and the token
        // all shift one place to the right. Forgetting it here keeps
        // eleven signatures free of a parameter none of them use.
        if ($current instanceof Route && $prefixed) {
            $current->forgetParameter('locale');
        }

        if (is_string($routed) && in_array($routed, $offered, true)) {
            $locale = $routed;
        } else {
            $chosen = $request->hasSession() ? $request->session()->get('locale') : null;

            if (is_string($chosen) && in_array($chosen, $offered, true)) {
                $locale = $chosen;
            } else {
                $locale = $this->negotiated($request, $offered);
                $negotiated = true;
            }
        }

        app()->setLocale($locale);

        $response = $next($request);

        // Only where the header actually decided. A page whose language
        // is written in its address answers the same document to
        // everyone, and telling caches otherwise splits a hit rate for
        // nothing; where it did decide, two visitors get two different
        // documents from the same URL, and an intermediate cache that
        // ignores this serves the first one's language to everyone
        // behind it.
        if ($negotiated) {
            $response->setVary('Accept-Language', false);
        }

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

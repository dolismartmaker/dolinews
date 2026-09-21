<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the visitor's chosen interface locale (D14): the choice lives
 * in the session, set by the /locale/{code} route, restricted to the
 * locales the service offers.
 */
class SetLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale');

        if (is_string($locale) && in_array($locale, (array) config('dolinews.locales', ['fr']), true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}

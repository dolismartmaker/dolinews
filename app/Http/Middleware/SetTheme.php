<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the visitor's chosen theme: the choice lives in the session, set by
 * the /theme/{choice} route, and reaches every layout as two shared values.
 *
 * Three choices and not two: "auto" follows prefers-color-scheme, which is the
 * sane default, but a reader must be able to contradict their machine for this
 * site alone. The dark variant of app.css fires on the classes below, never on
 * the media query alone.
 */
class SetTheme
{
    /**
     * The themes the service offers. 'auto' is the default.
     */
    public const CHOICES = ['auto', 'light', 'dark'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $choice = $request->session()->get('theme');

        if (! is_string($choice) || ! in_array($choice, self::CHOICES, true)) {
            $choice = 'auto';
        }

        View::share('themeChoice', $choice);
        // 'light' carries no class at all: nothing matches the dark variant,
        // so the sheet stays in its light form whatever the machine says.
        View::share('themeClass', match ($choice) {
            'dark' => 'dark',
            'auto' => 'theme-auto',
            default => '',
        });

        return $next($request);
    }
}

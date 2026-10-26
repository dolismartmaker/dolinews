<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Skins;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\View\FileViewFinder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the skin of the reader-facing pages: the visitor's choice from the
 * session when the instance lets them choose, the instance default otherwise.
 *
 * The skin's view directory goes in front of resources/views, so a view it
 * redefines wins and every other one falls back on the original. The
 * back-office is left out, its Livewire round trips included: it has the
 * single theme of the park whatever the visitor picked.
 */
class SetSkin
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Livewire prefixes its update route ("default.livewire.update").
        // The default paths are put back rather than left alone: a process
        // serving several requests still holds the previous one's skin.
        if ($request->routeIs('admin.*', '*livewire.*')) {
            self::apply(Skins::DEFAULT);

            return $next($request);
        }

        $skin = Skins::instanceDefault();

        if (Skins::visitorChoice()) {
            $choice = $request->session()->get('skin');

            if (is_string($choice) && in_array($choice, Skins::offered(), true)) {
                $skin = $choice;
            }
        }

        self::apply($skin);

        return $next($request);
    }

    /**
     * Point the view finder at a skin and share what the layouts read.
     *
     * The paths are reset before the skin's directory is put in front, and
     * the finder's cache of resolved names is flushed: a process serving
     * several requests would otherwise keep the views of the previous skin.
     */
    public static function apply(string $skin): void
    {
        $finder = View::getFinder();

        if ($finder instanceof FileViewFinder) {
            /** @var list<string> $paths */
            $paths = array_values((array) config('view.paths', [resource_path('views')]));
            $skinPath = Skins::viewPath($skin);

            if ($skinPath !== null && is_dir($skinPath)) {
                array_unshift($paths, $skinPath);
            }

            $finder->setPaths($paths);
            $finder->flush();
        }

        View::share('skinChoice', $skin);
        View::share('skinStylesheet', Skins::stylesheet($skin));
    }
}

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * Every route name the code names must exist.
 *
 * A `route('x')` on an undefined name throws only when the line runs, so it
 * survives every test that does not walk that exact branch and surfaces in
 * production instead. That is how `route('admin.login')` reached the server:
 * it sat in the back-office logout and in the admin guard, two paths no test
 * exercised, and the first moderator to sign out got a 500.
 *
 * Only literal names are checked. A name built at runtime is out of reach
 * here, and there is none in the codebase today.
 */

/**
 * @return array<string, list<string>> route name => files naming it
 */
function referencedRouteNames(): array
{
    $found = [];

    foreach (['app', 'resources/views', 'routes'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            $matches = [];

            preg_match_all('/route\(\s*[\'"]([a-zA-Z0-9_.\-]+)[\'"]/', $contents, $matches);

            foreach ($matches[1] as $name) {
                $found[$name][] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    return $found;
}

it('names no route that does not exist', function (): void {
    $missing = [];

    foreach (referencedRouteNames() as $name => $files) {
        if (! Route::has($name)) {
            $missing[] = $name.' ('.implode(', ', array_unique($files)).')';
        }
    }

    expect($missing)->toBe([]);
});

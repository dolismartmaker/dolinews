<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pur detector: a path in, a level out. Reads nothing but the config,
 * writes nothing (LARAVEL_HONEYPOT.md).
 */
class HoneypotMatcher
{
    public const LEVEL_INSTANT = 'instant';

    public const LEVEL_PROBABLE = 'probable';

    public const LEVEL_OBSERVED = 'observed';

    /**
     * @return array{level: string, reason: string}|null
     */
    public static function match(string $path): ?array
    {
        $normalised = self::normalise($path);

        if ($normalised === '') {
            return null;
        }

        foreach ((array) config('honeypot.ignore', []) as $prefix) {
            if (self::startsWith($normalised, (string) $prefix)) {
                return null;
            }
        }

        foreach ((array) config('honeypot.instant_paths', []) as $prefix) {
            if (self::startsWith($normalised, (string) $prefix)) {
                return ['level' => self::LEVEL_INSTANT, 'reason' => 'known-probe:'.$prefix];
            }
        }

        $extension = self::extension($normalised);

        if ($extension !== '' && $normalised !== 'index.php') {
            if (in_array($extension, self::lower(config('honeypot.instant_extensions', [])), true)) {
                return ['level' => self::LEVEL_INSTANT, 'reason' => 'secret-extension:'.$extension];
            }

            if (in_array($extension, self::lower(config('honeypot.extensions', [])), true)) {
                return ['level' => self::LEVEL_PROBABLE, 'reason' => 'impossible-extension:'.$extension];
            }
        }

        foreach ((array) config('honeypot.paths', []) as $prefix) {
            if (self::startsWith($normalised, (string) $prefix)) {
                return ['level' => self::LEVEL_PROBABLE, 'reason' => 'known-probe:'.$prefix];
            }
        }

        return null;
    }

    private static function normalise(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = (string) preg_replace('#/+#', '/', $path);

        return trim($path, '/');
    }

    private static function startsWith(string $path, string $prefix): bool
    {
        // Lu AVANT la normalisation : normalise() coupe les separateurs des
        // deux bouts, donc le tester ensuite ne trouve plus aucun slash final
        // et degrade en silence tout prefixe de repertoire en prefixe simple.
        $isDirectory = str_ends_with($prefix, '/');

        $prefix = self::normalise($prefix);

        if ($prefix === '') {
            return false;
        }

        // Un prefixe designant un repertoire garde son separateur dans la
        // comparaison, pour que "wp-admin/" prenne "wp-admin/install.php"
        // mais jamais "wp-administration".
        if ($isDirectory) {
            return stripos($path.'/', $prefix.'/') === 0;
        }

        return stripos($path, $prefix) === 0;
    }

    private static function extension(string $path): string
    {
        $segments = explode('/', $path);
        $last = (string) end($segments);
        $dot = strrpos($last, '.');

        return $dot === false ? '' : strtolower(substr($last, $dot + 1));
    }

    /**
     * @param  mixed  $values
     * @return array<int, string>
     */
    private static function lower($values): array
    {
        return array_map(
            static fn ($value): string => strtolower((string) $value),
            array_values((array) $values)
        );
    }
}

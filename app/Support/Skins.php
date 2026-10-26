<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * The skins of the reader-facing pages: public pages, authentication
 * screens and account area, never the back-office.
 *
 * A skin is a directory under resources/skins/<code>/ holding a stylesheet
 * (skin.css) and the views it redefines (views/). Any view a skin does not
 * redefine falls back on resources/views, so a skin may replace one partial
 * or the whole page set. The default skin is resources/views itself and has
 * no directory.
 *
 * A skin changes how the pages look, never what they say: every rule of the
 * spec on wording, order and channels holds identically in each of them.
 */
class Skins
{
    public const DEFAULT = 'default';

    /**
     * The skins the instance offers, the default first.
     *
     * A configured code with no stylesheet on disk is dropped and logged:
     * offering it would serve pages that load no CSS at all.
     *
     * @return list<string>
     */
    public static function offered(): array
    {
        $offered = [self::DEFAULT];

        foreach ((array) config('dolinews.skins.offered', []) as $code) {
            if (! is_string($code) || $code === self::DEFAULT || in_array($code, $offered, true)) {
                continue;
            }

            if (preg_match('/^[a-z][a-z0-9-]*$/', $code) !== 1 || ! is_file(self::directory($code).'/skin.css')) {
                Log::warning('Skin dropped: no stylesheet at resources/skins/'.$code.'/skin.css', ['skin' => $code]);

                continue;
            }

            $offered[] = $code;
        }

        return $offered;
    }

    /**
     * The skin of a visitor who has not chosen one, and of every visitor
     * when the choice is closed.
     */
    public static function instanceDefault(): string
    {
        $configured = config('dolinews.skins.default', self::DEFAULT);

        if (is_string($configured) && in_array($configured, self::offered(), true)) {
            return $configured;
        }

        Log::warning('Configured default skin is not offered, falling back on the default one', [
            'skin' => is_scalar($configured) ? (string) $configured : get_debug_type($configured),
        ]);

        return self::DEFAULT;
    }

    /**
     * Whether a visitor may pick a skin of their own.
     */
    public static function visitorChoice(): bool
    {
        return (bool) config('dolinews.skins.visitor_choice', true) && count(self::offered()) > 1;
    }

    /**
     * Vite entry of a skin's stylesheet.
     */
    public static function stylesheet(string $code): string
    {
        return $code === self::DEFAULT ? 'resources/css/app.css' : 'resources/skins/'.$code.'/skin.css';
    }

    /**
     * Directory of the views a skin redefines, null for the default skin.
     */
    public static function viewPath(string $code): ?string
    {
        return $code === self::DEFAULT ? null : self::directory($code).'/views';
    }

    private static function directory(string $code): string
    {
        return resource_path('skins/'.$code);
    }
}

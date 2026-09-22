<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Seo;

/**
 * The image a page hands to whoever renders a preview of it.
 *
 * A link shared on a forum, in a chat room or on a social network is
 * rendered from the page's own declarations: without an image, the
 * announcement of a security fix travels as a bare URL. The illustration
 * of the announcement is used when it has one, the editor's logo on a
 * sheet, and a neutral service image otherwise - one that carries no
 * translatable sentence, since the same file serves ten languages.
 */
final class PageImage
{
    /**
     * An absolute URL for a stored medium, or null when there is none.
     *
     * Media URLs come from the storage disk, which yields a root-relative
     * path under some configurations; a preview renderer never resolves
     * one against the page it read.
     */
    public static function absolute(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return url($url);
    }

    /**
     * The service image, used by every page that offers none of its own.
     */
    public static function fallback(): string
    {
        return asset('images/og-dolinews.png');
    }

    public static function for(?string $url): string
    {
        return self::absolute($url) ?? self::fallback();
    }
}

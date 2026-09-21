<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * Maturity of an announced version (SPEC 4.3).
 *
 * An enum and not a boolean: deprecated is the rarest and most useful
 * value of the lot. Non-stable maturities are excluded from feeds by
 * default (SPEC 6.2).
 */
enum Maturity: string
{
    case ALPHA = 'alpha';
    case BETA = 'beta';
    case RC = 'rc';
    case STABLE = 'stable';
    case DEPRECATED = 'deprecated';

    /**
     * Whether this maturity is shown in the default feed view: only
     * stable is (SPEC 6.2), the reader opts in to the rest.
     */
    public function isDefaultVisible(): bool
    {
        return $this === self::STABLE;
    }

    /**
     * French label shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::ALPHA => 'alpha',
            self::BETA => 'beta',
            self::RC => 'release candidate',
            self::STABLE => 'stable',
            self::DEPRECATED => 'obsolète',
        };
    }
}

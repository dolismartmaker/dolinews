<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * How a Dolibarr compatibility claim was established (SPEC 4.3).
 *
 * declared: taken from the module descriptor, often the generator default.
 * tested: really exercised on the said major version.
 * experimental: works in the author's hands, unproven.
 */
enum CompatStatus: string
{
    case DECLARED = 'declared';
    case TESTED = 'tested';
    case EXPERIMENTAL = 'experimental';

    /**
     * Label shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::DECLARED => __('compatibilité déclarée'),
            self::TESTED => __('compatibilité testée'),
            self::EXPERIMENTAL => __('compatibilité expérimentale'),
        };
    }
}

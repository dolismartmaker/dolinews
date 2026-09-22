<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * Life-cycle of a project sheet (SPEC 4.2).
 *
 * The sheet carries no compatibility, only a status: an unmaintained or
 * archived sheet stays honest, it never rots (SPEC D1).
 */
enum ProjectStatus: string
{
    case ACTIVE = 'active';
    case UNMAINTAINED = 'unmaintained';
    case ARCHIVED = 'archived';

    /**
     * Label shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => __('actif'),
            self::UNMAINTAINED => __('non maintenu'),
            self::ARCHIVED => __('archivé'),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * Nature of a version publication, reworked from freshmeat (SPEC 4.3).
 *
 * Null on announcements: the notion only exists for a release.
 */
enum Focus: string
{
    case DOC = 'doc';
    case CLEANUP = 'cleanup';
    case FEATURE_MINOR = 'feature_minor';
    case FEATURE_MAJOR = 'feature_major';
    case BUGFIX_MINOR = 'bugfix_minor';
    case BUGFIX_MAJOR = 'bugfix_major';
    case SECURITY = 'security';
    case COMPAT = 'compat';
    case EOL = 'eol';

    /**
     * Label shown in the interface (accented, ASCII punctuation).
     */
    public function label(): string
    {
        return match ($this) {
            self::DOC => __('Documentation'),
            self::CLEANUP => __('Nettoyage'),
            self::FEATURE_MINOR => __('Fonctionnalité mineure'),
            self::FEATURE_MAJOR => __('Fonctionnalité majeure'),
            self::BUGFIX_MINOR => __('Correctif mineur'),
            self::BUGFIX_MAJOR => __('Correctif majeur'),
            self::SECURITY => __('Sécurité'),
            self::COMPAT => __('Compatibilité'),
            self::EOL => __('Fin de vie'),
        };
    }
}

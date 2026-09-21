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
     * French label shown in the interface (accented, ASCII punctuation).
     */
    public function label(): string
    {
        return match ($this) {
            self::DOC => 'Documentation',
            self::CLEANUP => 'Nettoyage',
            self::FEATURE_MINOR => 'Fonctionnalité mineure',
            self::FEATURE_MAJOR => 'Fonctionnalité majeure',
            self::BUGFIX_MINOR => 'Correctif mineur',
            self::BUGFIX_MAJOR => 'Correctif majeur',
            self::SECURITY => 'Sécurité',
            self::COMPAT => 'Compatibilité',
            self::EOL => 'Fin de vie',
        };
    }
}

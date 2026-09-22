<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * What a visitor says is wrong with a published content.
 *
 * The set is the one of the sanction classes written in the rules
 * (SPEC 9.3), and nothing else: a report that could not be named in the
 * vocabulary of the rules could not be sanctioned either, since a
 * sanction only rests on a numbered rule existing at the time of the
 * facts (SPEC 9.2).
 *
 * It qualifies nothing on its own: the reporter states what they see,
 * the moderator invokes the rule. OTHER exists because a reporter who
 * finds no box ticks the nearest one, which is worse than a free text.
 */
enum ReportReason: string
{
    case ILLEGAL = 'illegal';
    case SPAM = 'spam';
    case IMPERSONATION = 'impersonation';
    case MISLEADING_LINK = 'misleading_link';
    case RIGHTS = 'rights';
    case SECURITY_QUEUE = 'security_queue';
    case OTHER = 'other';

    /**
     * Human-readable reason, shown to the reporter and to the team.
     */
    public function label(): string
    {
        return match ($this) {
            self::ILLEGAL => __('Contenu illicite'),
            self::SPAM => __('Spam ou contenu sans rapport'),
            self::IMPERSONATION => __('Usurpation d\'identité ou de fiche'),
            self::MISLEADING_LINK => __('Lien trompeur'),
            self::RIGHTS => __('Atteinte aux droits d\'un tiers'),
            self::SECURITY_QUEUE => __('Annonce marquée sécurité sans objet de sécurité'),
            self::OTHER => __('Autre'),
        };
    }
}

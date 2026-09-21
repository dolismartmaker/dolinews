<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * By which route an article reached the published state (SPEC 4.3/5.1).
 *
 * quorum: three moderators accepted it. admin_override: the super admin
 * published without quorum (logged with a mandatory motive). bootstrap:
 * the service bootstrap phase, before the moderation team existed.
 */
enum PublicationMode: string
{
    case QUORUM = 'quorum';
    case ADMIN_OVERRIDE = 'admin_override';
    case BOOTSTRAP = 'bootstrap';
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dolinews\Moderation\ModerationService;
use Illuminate\Console\Command;

/**
 * Auto-cancel conflict-of-interest withdrawals left unconfirmed beyond
 * seven days (SPEC 9.6): the effect is lifted, the cancellation is
 * journalled without moderator_user_id.
 *
 * Legal-obligation withdrawals are exempt: they stay in force, the
 * confirmation only documents them.
 */
class ExpireModerationConfirmationsCommand extends Command
{
    protected $signature = 'dolinews:expire-moderation-confirmations';

    protected $description = 'Auto-cancel unconfirmed conflict-of-interest moderation acts';

    public function handle(ModerationService $moderation): int
    {
        $count = $moderation->expireUnconfirmed();

        $this->info("Auto-cancelled {$count} unconfirmed acts.");

        return self::SUCCESS;
    }
}

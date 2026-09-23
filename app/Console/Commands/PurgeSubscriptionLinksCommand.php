<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dolinews\Subscriptions\PublicSubscriptionService;
use Illuminate\Console\Command;

/**
 * Drop the subscription links whose delay has run out (SPEC 6.4).
 *
 * With them go the addresses typed into the public form that nobody
 * ever confirmed: an address the service was never allowed to write to
 * is not one it keeps.
 */
class PurgeSubscriptionLinksCommand extends Command
{
    protected $signature = 'dolinews:purge-subscription-links';

    protected $description = 'Purge expired subscription confirmation and preferences links';

    public function handle(PublicSubscriptionService $subscriptions): int
    {
        $count = $subscriptions->purgeExpired();

        $this->info("Purged {$count} expired subscription links.");

        return self::SUCCESS;
    }
}

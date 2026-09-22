<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dolinews\Enums\EmailDigest;
use App\Domain\Dolinews\Subscriptions\EmailSubscriptionService;
use Illuminate\Console\Command;

/**
 * Subscription mails of one cadence (SPEC 6.4).
 *
 * One command per cadence rather than one deciding on its own: the
 * scheduler says when an instant run, a daily one and a weekly one
 * happen, and reading routes/console.php is then enough to know what
 * leaves the service and when.
 *
 * Idempotent and safe to re-run: the per-account cursor and the minimum
 * gap between two mails both live in the service.
 */
class SendSubscriptionDigestsCommand extends Command
{
    protected $signature = 'dolinews:send-digests {--cadence=instant : instant, daily or weekly}';

    protected $description = 'Send the subscription mails of one cadence';

    public function handle(EmailSubscriptionService $subscriptions): int
    {
        $cadence = EmailDigest::tryFrom((string) $this->option('cadence'));

        if ($cadence === null || $cadence === EmailDigest::NONE) {
            $this->error('Cadence inconnue : '.(string) $this->option('cadence'));

            return self::FAILURE;
        }

        $sent = $subscriptions->sendDue($cadence);

        $this->info(sprintf('%s : %d courriel(s) envoyé(s).', $cadence->value, $sent));

        return self::SUCCESS;
    }
}

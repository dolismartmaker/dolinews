<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * Cadence of the subscription mails (SPEC 6.4).
 *
 * Four values rather than one: an integrator wants a security fix the
 * hour it is published, a company director wants one mail a week and
 * unsubscribes from anything noisier. Serving only one of the two loses
 * the other, and the cadence is the reader's to choose.
 *
 * NONE is the default: an account subscribes to mails, it is never
 * subscribed by the act of creating an account.
 */
enum EmailDigest: string
{
    case NONE = 'none';
    case INSTANT = 'instant';
    case DAILY = 'daily';
    case WEEKLY = 'weekly';

    /**
     * Cadences that produce a mail, in the order the account page
     * offers them.
     *
     * @return list<self>
     */
    public static function sending(): array
    {
        return [self::INSTANT, self::DAILY, self::WEEKLY];
    }

    /**
     * Shortest gap between two mails of this cadence.
     *
     * The scheduler already paces the sends; this is the guard that
     * keeps a command run by hand - or a cron firing twice - from
     * mailing a daily digest three times in one morning.
     */
    public function minimumGapHours(): int
    {
        return match ($this) {
            self::NONE => 0,
            self::INSTANT => 0,
            self::DAILY => 20,
            self::WEEKLY => 6 * 24,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Subscriptions;

use App\Domain\Dolinews\Enums\EmailDigest;
use App\Domain\Dolinews\Enums\Focus;
use App\Domain\Dolinews\Enums\Maturity;
use App\Domain\Dolinews\Feeds\FeedService;
use App\Domain\Dolinews\Models\Article;
use App\Models\User;
use App\Notifications\SubscriptionDigest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Subscription mails (SPEC 6.4).
 *
 * The tokenized feed reaches whoever runs a feed reader. The Dolibarr
 * user this service exists for does not run one, so the announcement of
 * a security fix on a module they deployed never reaches them. Mail is
 * the channel that does.
 *
 * Three guarantees hold the whole design together:
 *
 *  - subscribing never mails the archive. The cursor is set at the
 *    instant the subscription is turned on, and only what is published
 *    after it is ever sent;
 *  - a back-dated publication (SPEC 5.1) mails nobody. Its date lies in
 *    the past, therefore below every cursor. This is not a special case
 *    in the code, it falls out of the cursor;
 *  - the cursor advances to the date of the newest article actually
 *    sent, never to "now". A send that fails halfway leaves the cursor
 *    where it was and the next run picks the same articles up again.
 */
class EmailSubscriptionService
{
    /**
     * Articles carried by one mail. Past this, the digest is a feed and
     * the reader should be reading the feed.
     */
    private const MAX_ARTICLES = 25;

    public function __construct(
        private readonly FeedService $feeds,
    ) {}

    /**
     * Write the mail preferences of an account.
     *
     * @param  array{watches_all?: bool, focus?: list<string>|null, maturities?: list<string>|null, locale?: string|null}  $options
     */
    public function updatePreferences(User $user, EmailDigest $digest, array $options = []): void
    {
        $wasSilent = $user->email_digest === EmailDigest::NONE;

        $user->email_digest = $digest;
        $user->watches_all = (bool) ($options['watches_all'] ?? false);
        $user->watch_all_focus_filter = $this->cleanFocus($options['focus'] ?? null);
        $user->watch_all_maturity_filter = $this->cleanMaturities($options['maturities'] ?? null);

        if (($options['locale'] ?? null) !== null) {
            $user->locale = (string) $options['locale'];
        }

        if ($digest !== EmailDigest::NONE) {
            $user->unsubscribe_token ??= Str::random(32);

            // Turning the subscription on starts the clock here: the
            // reader asked to be told what happens next, not to receive
            // the back catalogue in one mail.
            if ($wasSilent || $user->digest_cursor_at === null) {
                $user->digest_cursor_at = now();
            }
        }

        $user->save();
    }

    /**
     * Stop the mails from the footer link, without a session: the reader
     * unsubscribes from the mail itself or they do not unsubscribe at
     * all. The watches are left alone - the personal feed and the
     * account page keep working, only the mails stop.
     */
    public function unsubscribe(User $user): void
    {
        $user->email_digest = EmailDigest::NONE;
        $user->save();

        Log::info('EmailSubscriptionService: reader unsubscribed from mails', [
            'user_id' => $user->getKey(),
        ]);
    }

    /**
     * The account behind an unsubscribe token, or null.
     */
    public function byUnsubscribeToken(string $token): ?User
    {
        /** @var User|null $user */
        $user = User::query()->where('unsubscribe_token', $token)->first();

        return $user;
    }

    /**
     * What one account has not been told about yet.
     *
     * @return array<int, Article>
     */
    public function pendingArticles(User $user): array
    {
        return $this->feeds->personalFeed(
            $user,
            self::MAX_ARTICLES,
            $user->digest_cursor_at ?? now(),
        );
    }

    /**
     * Send one cadence's mails, and return how many went out.
     *
     * The gap guard is not the scheduler's job duplicated: a command run
     * by hand, or a cron firing twice, would otherwise mail the same
     * daily digest twice in a morning, and that is how an address stops
     * reading them.
     */
    public function sendDue(EmailDigest $cadence): int
    {
        if ($cadence === EmailDigest::NONE) {
            return 0;
        }

        $sent = 0;
        $gapHours = $cadence->minimumGapHours();

        User::query()->subscribedTo($cadence)->chunkById(100, function ($users) use ($cadence, $gapHours, &$sent): void {
            foreach ($users as $user) {
                if ($gapHours > 0
                    && $user->digest_sent_at !== null
                    && $user->digest_sent_at->diffInHours(now()) < $gapHours) {
                    continue;
                }

                $articles = $this->pendingArticles($user);

                if ($articles === []) {
                    continue;
                }

                $user->notify(new SubscriptionDigest($articles, $cadence));

                // The cursor follows what was actually sent, not the
                // clock: anything published while this loop runs stays
                // ahead of it and goes out next time.
                $newest = max(array_map(
                    static fn (Article $article): int => $article->published_at?->getTimestamp() ?? 0,
                    $articles,
                ));

                $user->digest_cursor_at = now()->setTimestamp($newest);
                $user->digest_sent_at = now();
                $user->save();

                $sent++;
            }
        });

        Log::info('EmailSubscriptionService: subscription mails sent', [
            'cadence' => $cadence->value,
            'count' => $sent,
        ]);

        return $sent;
    }

    /**
     * Focus values kept by the whole-feed watch, null meaning the site
     * default.
     *
     * @param  list<string>|null  $values
     * @return list<string>|null
     */
    private function cleanFocus(?array $values): ?array
    {
        $valid = array_values(array_filter(
            $values ?? [],
            static fn (string $value): bool => Focus::tryFrom($value) !== null,
        ));

        return $valid === [] ? null : $valid;
    }

    /**
     * Maturity values kept by the whole-feed watch, null meaning the
     * site default (stable only, SPEC 6.2).
     *
     * @param  list<string>|null  $values
     * @return list<string>|null
     */
    private function cleanMaturities(?array $values): ?array
    {
        $valid = array_values(array_filter(
            $values ?? [],
            static fn (string $value): bool => Maturity::tryFrom($value) !== null,
        ));

        return $valid === [] ? null : $valid;
    }
}

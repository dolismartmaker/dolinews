<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Review;

use App\Domain\Dolinews\Models\Article;
use App\Models\User as Account;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Who the circuit mails actually reach (SPEC 5.1/5.5).
 *
 * The queue is multilingual (D14): an announcement written in Greek,
 * mailed to a moderator who reads neither Greek nor its alphabet, is
 * noise - and a mail nobody can act on is how a team learns to filter
 * the channel that was supposed to alert it, exactly what SPEC 9.9
 * refuses for reports.
 *
 * Two lines bound the idea, and they are what the rest of this class
 * implements:
 *
 * - this filters NOTIFICATION, never RIGHT. The queue stays open to the
 *   whole team, the quorum does not move, and a moderator who reads an
 *   entry nobody addressed to them reviews it like any other;
 * - it never leaves an article with fewer addressees than it takes
 *   accords to publish. Below that, the whole team is mailed: an
 *   announcement in a language no one declared would otherwise sleep in
 *   the queue, or worse gather two accords out of three and stall with
 *   nobody else aware of it. "Sans relance, la file meurt en silence"
 *   (SPEC 5.1) applies to the first mail too.
 */
class ReviewAudience
{
    /**
     * The team to mail about an article, language-filtered with the
     * quorum fallback.
     *
     * @param  int|null  $exceptId  Account left out before anything else:
     *                              the author, or the sender of the
     *                              message being notified. It is excluded
     *                              BEFORE the count, since it does not
     *                              count in its own quorum either
     *                              (SPEC 5.1).
     * @param  list<int>  $keepIds  Accounts kept whatever language they
     *                              declare - a moderator already engaged
     *                              in the thread must keep receiving its
     *                              answers.
     * @return Collection<int, Account>
     */
    public function forArticle(Article $article, ?int $exceptId = null, array $keepIds = []): Collection
    {
        $team = Account::query()
            ->reviewTeam()
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->get();

        $audience = $this->filter($team, (string) $article->locale, $article->requiredAccords());

        if ($keepIds !== []) {
            $kept = $team->filter(
                fn (Account $account): bool => in_array((int) $account->getKey(), $keepIds, true),
            );

            $audience = $audience
                ->concat($kept->all())
                ->unique(fn (Account $account): int => (int) $account->getKey())
                ->values();
        }

        return $audience;
    }

    /**
     * The team to mail about a content report (SPEC 9.9).
     *
     * The language filtered on is the report's own: what the mail
     * carries is the reporter's text, and someone has to read it. One
     * addressee is enough here - a report is a reading, not a quorum.
     *
     * @return Collection<int, Account>
     */
    public function forLocale(string $locale, int $minimum = 1): Collection
    {
        return $this->filter(Account::query()->reviewTeam()->get(), $locale, $minimum);
    }

    /**
     * The shared rule: keep the declared readers, fall back to the whole
     * team when they are too few to carry the decision.
     *
     * @param  Collection<int, Account>  $team
     * @return Collection<int, Account>
     */
    private function filter(Collection $team, string $locale, int $minimum): Collection
    {
        if ($locale === '') {
            return $team;
        }

        $readers = $team
            ->filter(fn (Account $account): bool => $account->readsContentLocale($locale))
            ->values();

        if ($readers->count() >= $minimum) {
            return $readers;
        }

        Log::info('ReviewAudience: language filter fell back to the whole team', [
            'locale' => $locale,
            'readers' => $readers->count(),
            'minimum' => $minimum,
            'team' => $team->count(),
        ]);

        return $team;
    }
}

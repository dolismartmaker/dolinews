<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Subscriptions;

use App\Domain\Dolinews\Enums\EmailDigest;
use App\Domain\Dolinews\Enums\Focus;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\SubscriptionLink;
use App\Models\User;
use App\Notifications\SubscriptionConfirmation;
use App\Notifications\SubscriptionManageLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Subscribing with an address and nothing else (SPEC 6.4).
 *
 * The reader this service exists for - the one running Dolibarr, who
 * must hear about a security fix on a module deployed at their place -
 * will not create an account, pick a password and cross a back-office to
 * get there. So they do not: they type their address on the sheet of the
 * project they follow, click the link they receive, and are subscribed.
 *
 * The account still exists underneath, and that is deliberate: watches,
 * cadence, anti-archive cursor, one-click unsubscribe and personal feed
 * all already hang off it. A second, anonymous subscription stack beside
 * it would duplicate every one of those rules, and the two would drift.
 *
 * Two things the click protects, and they are the reason it is not
 * optional:
 *
 *  - the address belongs to whoever typed it, or it does not. Nothing
 *    is created, stored as an account, or mailed again until someone
 *    proves they read that mailbox;
 *  - the domain keeps its deliverability. A service that mails people
 *    who never asked loses it once and never gets it back (SPEC 6.4).
 */
class PublicSubscriptionService
{
    public function __construct(
        private readonly WatchService $watches,
        private readonly EmailSubscriptionService $emails,
    ) {}

    /**
     * Take an address from a project or editor sheet and mail it a
     * confirmation link. Nothing else happens yet, on purpose.
     *
     * Returns silently whatever the address: the answer shown to the
     * visitor is the same in every case, so the form never tells a
     * stranger whether an address is already known here.
     */
    public function requestSubscription(
        string $email,
        ?Project $project = null,
        ?Editor $editor = null,
        bool $securityOnly = false,
    ): void {
        $email = $this->normalise($email);

        $link = $this->issue($email, SubscriptionLink::PURPOSE_CONFIRM, [
            'project_id' => $project?->getKey(),
            'editor_id' => $editor?->getKey(),
            'focus' => $securityOnly ? [Focus::SECURITY->value] : null,
        ], now()->addHours((int) config('dolinews.subscriptions.confirm_ttl_hours', 48)));

        Notification::route('mail', $email)->notify(new SubscriptionConfirmation(
            $link->token,
            $project->name ?? $editor->name ?? null,
            $securityOnly,
        ));

        Log::info('PublicSubscriptionService: confirmation mailed', [
            'project_id' => $project?->getKey(),
            'editor_id' => $editor?->getKey(),
        ]);
    }

    /**
     * Turn a confirmed link into a live subscription, and return the
     * account it belongs to. Null when the link is unknown, expired or
     * already used - the page says which, since "nothing happened" with
     * no reason reads as a broken service.
     */
    public function confirm(string $token): ?User
    {
        $link = $this->pending($token, SubscriptionLink::PURPOSE_CONFIRM);

        if ($link === null) {
            Log::info('PublicSubscriptionService: confirmation refused, link not pending');

            return null;
        }

        /** @var array{project_id?: int|null, editor_id?: int|null, focus?: list<string>|null} $payload */
        $payload = $link->payload ?? [];

        return DB::transaction(function () use ($link, $payload): User {
            $user = $this->accountFor($link->email);

            $filters = ['focus' => $payload['focus'] ?? null];

            $project = isset($payload['project_id'])
                ? Project::query()->find($payload['project_id'])
                : null;

            if ($project instanceof Project) {
                $this->watchOnce($user, $project, $filters);
            }

            $editor = isset($payload['editor_id'])
                ? Editor::query()->find($payload['editor_id'])
                : null;

            if ($editor instanceof Editor) {
                $this->watchOnce($user, $editor, $filters);
            }

            // Mails start here and not a moment earlier: the cursor is
            // set by updatePreferences, so confirming never mails the
            // archive of what was published while the link waited.
            if ($user->email_digest === EmailDigest::NONE) {
                $this->emails->updatePreferences($user, EmailDigest::INSTANT, [
                    'watches_all' => $user->watches_all,
                    'watches_all_security' => $user->watches_all_security,
                ]);
            }

            $link->used_at = now();
            $link->save();

            Log::info('PublicSubscriptionService: subscription confirmed', [
                'user_id' => $user->getKey(),
            ]);

            return $user;
        });
    }

    /**
     * Mail the preferences link of an existing account.
     *
     * Nothing is mailed to an address no account carries, and the caller
     * is told nothing either: the form is public, and answering
     * differently would turn it into an address prober.
     */
    public function requestManageLink(string $email): void
    {
        $email = $this->normalise($email);

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            Log::info('PublicSubscriptionService: preferences link asked for an unknown address');

            return;
        }

        $link = $this->issue($email, SubscriptionLink::PURPOSE_MANAGE, null, now()->addMinutes(
            (int) config('dolinews.subscriptions.manage_ttl_minutes', 60),
        ));

        $user->notify(new SubscriptionManageLink($link->token));

        Log::info('PublicSubscriptionService: preferences link mailed', [
            'user_id' => $user->getKey(),
        ]);
    }

    /**
     * The account a preferences link opens, or null when the link is
     * unknown, expired or spent.
     */
    public function accountForManageLink(string $token): ?User
    {
        $link = $this->pending($token, SubscriptionLink::PURPOSE_MANAGE);

        if ($link === null) {
            return null;
        }

        $user = User::query()->where('email', $link->email)->first();

        return $user instanceof User ? $user : null;
    }

    /**
     * Drop what has run out: expired links, and with them every address
     * typed into the form that nobody ever confirmed. An address the
     * service was never allowed to write to is not one it keeps.
     */
    public function purgeExpired(): int
    {
        $count = SubscriptionLink::query()
            ->where('expires_at', '<', now())
            ->delete();

        Log::info('PublicSubscriptionService: expired links purged', ['count' => $count]);

        return $count;
    }

    /**
     * The account behind an address, created without a password when it
     * is new: the subscriber never picked one, and a null password
     * authenticates nobody.
     */
    private function accountFor(string $email): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user instanceof User) {
            return $user;
        }

        $user = new User;
        $user->name = Str::before($email, '@');
        $user->email = $email;
        $user->password = null;
        // The click on the link proves the address as much as the socle
        // verification mail would, and it is the same act: asking for a
        // second proof of the same mailbox loses the subscriber.
        $user->email_verified_at = now();
        $user->save();

        Log::info('PublicSubscriptionService: reader account created from a subscription', [
            'user_id' => $user->getKey(),
        ]);

        return $user;
    }

    /**
     * Add a watch the account does not already hold. toggleProject and
     * toggleEditor are toggles: called on an existing watch they would
     * REMOVE it, and confirming a link twice would unsubscribe the
     * reader who clicked it once too often.
     *
     * @param  array{focus?: list<string>|null}  $filters
     */
    private function watchOnce(User $user, Project|Editor $target, array $filters): void
    {
        $existing = $target instanceof Project
            ? $user->projectWatches()->where('project_id', $target->getKey())->exists()
            : $user->editorWatches()->where('editor_id', $target->getKey())->exists();

        if ($existing) {
            return;
        }

        if ($target instanceof Project) {
            $this->watches->toggleProject($user, $target, $filters);

            return;
        }

        $this->watches->toggleEditor($user, $target, $filters);
    }

    /**
     * Mint a link, dropping the pending ones of the same address and
     * purpose: only the newest mail may be acted on, so a link that
     * leaked from an old mailbox copy stops working as soon as its owner
     * asks for a fresh one.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function issue(string $email, string $purpose, ?array $payload, \DateTimeInterface $expiresAt): SubscriptionLink
    {
        SubscriptionLink::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->delete();

        /** @var SubscriptionLink $link */
        $link = SubscriptionLink::query()->create([
            'email' => $email,
            'token' => Str::random(32),
            'purpose' => $purpose,
            'payload' => $payload,
            'expires_at' => $expiresAt,
        ]);

        return $link;
    }

    /**
     * A link of that purpose still open, or null.
     */
    private function pending(string $token, string $purpose): ?SubscriptionLink
    {
        /** @var SubscriptionLink|null $link */
        $link = SubscriptionLink::query()
            ->where('token', $token)
            ->where('purpose', $purpose)
            ->first();

        if ($link === null || ! $link->isPending()) {
            return null;
        }

        return $link;
    }

    /**
     * One address, one account: the form is typed by hand, and
     * "Jean.Dupont@Example.COM" must not open a second subscription
     * beside "jean.dupont@example.com".
     */
    private function normalise(string $email): string
    {
        return Str::lower(trim($email));
    }
}

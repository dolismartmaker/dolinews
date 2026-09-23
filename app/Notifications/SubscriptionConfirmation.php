<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one mail an address gets before it is subscribed to anything
 * (SPEC 6.4).
 *
 * It carries no announcement and no archive: at this point nobody has
 * proved they read this mailbox, and the form that triggered it takes an
 * address typed by whoever passes by. Either the link is clicked and the
 * subscription exists, or nothing more is ever sent here and the pending
 * link is purged.
 *
 * Sent to an anonymous notifiable, since no account exists yet - which
 * is the point.
 */
class SubscriptionConfirmation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly ?string $targetName = null,
        public readonly bool $securityOnly = false,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[DoliNews] '.__('Confirmez votre abonnement'))
            ->markdown('mail.subscription-confirmation', [
                'url' => route('subscribe.confirm', ['token' => $this->token]),
                'targetName' => $this->targetName,
                'securityOnly' => $this->securityOnly,
                'hours' => (int) config('dolinews.subscriptions.confirm_ttl_hours', 48),
            ]);
    }
}

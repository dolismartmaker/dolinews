<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The link that opens the preferences of a subscriber (SPEC 6.4).
 *
 * It opens a page, not a session: the same table carries readers,
 * contributors and moderators, and a mail that logged its recipient in
 * would make the mailbox the single factor of an account that may
 * publish. What the page can do is bounded to the subscription itself.
 *
 * Short-lived, because it is asked for at the moment it is used.
 */
class SubscriptionManageLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $token,
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
            ->subject('[DoliNews] '.__('Vos préférences d\'abonnement'))
            ->markdown('mail.subscription-manage', [
                'url' => route('subscriptions.preferences', ['token' => $this->token]),
                'minutes' => (int) config('dolinews.subscriptions.manage_ttl_minutes', 60),
            ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Dolinews\Enums\EmailDigest;
use App\Domain\Dolinews\Enums\Focus;
use App\Domain\Dolinews\Models\Article;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * The subscription mail of a reader account (SPEC 6.4).
 *
 * Every one of them carries the unsubscribe footer, and the mail headers
 * that let a mail client offer the same thing without opening it. A
 * subscription one cannot leave from the mail itself is reported as
 * spam instead, which costs the whole domain its deliverability.
 *
 * Distinct from the review circuit mails (ReviewThreadMessage,
 * ArticleSubmitted): those answer an act of their recipient and carry no
 * unsubscribe link, since leaving them would mean leaving the circuit.
 */
class SubscriptionDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, Article>  $articles
     */
    public function __construct(
        public readonly array $articles,
        public readonly EmailDigest $cadence,
    ) {}

    /**
     * @param  User  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $unsubscribeUrl = route('unsubscribe.show', [
            'token' => (string) $notifiable->unsubscribe_token,
        ]);

        return (new MailMessage)
            ->subject($this->subject())
            ->markdown('mail.subscription-digest', [
                'articles' => $this->articles,
                'cadence' => $this->cadence,
                'unsubscribeUrl' => $unsubscribeUrl,
                'accountUrl' => route('account.show'),
            ])
            // RFC 8058: the one-click header points at the POST route,
            // the footer link at the confirmation page. A mail client
            // acts without a human, so it gets the endpoint that asks
            // nothing; a reader gets the page that says what stops.
            ->withSymfonyMessage(function (Email $message) use ($unsubscribeUrl): void {
                $headers = $message->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }

    /**
     * Subject line: the security announcements come first in it, because
     * that is the one an integrator opens the day it lands.
     */
    private function subject(): string
    {
        $count = count($this->articles);

        $security = array_filter(
            $this->articles,
            static fn (Article $article): bool => $article->focus === Focus::SECURITY,
        );

        $head = $count === 1
            ? $this->articles[0]->title
            : __(':count nouvelles annonces', ['count' => $count]);

        return $security !== []
            ? '[DoliNews] '.__('Sécurité').' - '.$head
            : '[DoliNews] '.$head;
    }
}

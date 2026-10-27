<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Dolinews\Enums\EmailDigest;
use App\Domain\Dolinews\Enums\Focus;
use App\Domain\Dolinews\Models\Article;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
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

        // Each entry shows its source logo and its featured image, which
        // a translation borrows from its source (SPEC 4.3).
        (new Collection($this->articles))->load([
            'editor.logo', 'project.logo', 'featuredMedia', 'media', 'source.featuredMedia', 'source.media',
        ]);

        return (new MailMessage)
            ->subject($this->subject())
            ->markdown('mail.subscription-digest', [
                'articles' => $this->articles,
                'cadence' => $this->cadence,
                'heading' => $this->heading(),
                'intro' => $this->intro(),
                'preheader' => $this->preheader(),
                'unsubscribeUrl' => $unsubscribeUrl,
                // The public preferences page and not the account
                // screen: a reader who subscribed with their address
                // alone has no password to open the second one, and a
                // fresh link is mailed from there (SPEC 6.4).
                'preferencesUrl' => route('subscriptions.preferences.request'),
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
     * Heading of the mail, after the cadence the reader chose: a weekly
     * mail says it covers the week, so the reader knows nothing is missing.
     */
    private function heading(): string
    {
        $single = count($this->articles) === 1;

        return match ($this->cadence) {
            EmailDigest::DAILY => $single ? __('L\'annonce du jour') : __('Les annonces du jour'),
            EmailDigest::WEEKLY => $single ? __('L\'annonce de la semaine') : __('Les annonces de la semaine'),
            default => $single ? __('Nouvelle annonce') : __('Nouvelles annonces'),
        };
    }

    /**
     * Line under the heading, only when several announcements include a
     * security one: the order stays that of the feed, so the reader is
     * told up front that one of them is marked further down.
     */
    private function intro(): ?string
    {
        $count = count($this->articles);
        $security = count($this->securityArticles());

        if ($count < 2 || $security === 0) {
            return null;
        }

        return __(':total annonces, dont :count de sécurité', ['total' => $count, 'count' => $security]);
    }

    /**
     * Inbox preview: the summary of a single announcement, the titles of
     * several. Left to itself, the mail client showed the service name
     * and the heading, which say nothing the subject does not.
     */
    private function preheader(): string
    {
        $text = count($this->articles) === 1
            ? $this->articles[0]->summary
            : implode(' - ', array_map(static fn (Article $article): string => $article->title, $this->articles));

        return Str::limit(Str::squish($text), 140);
    }

    /**
     * @return array<int, Article>
     */
    private function securityArticles(): array
    {
        return array_filter(
            $this->articles,
            static fn (Article $article): bool => $article->focus === Focus::SECURITY,
        );
    }

    /**
     * Subject line: the security announcements come first in it, because
     * that is the one an integrator opens the day it lands.
     */
    private function subject(): string
    {
        $count = count($this->articles);

        $security = $this->securityArticles();

        $head = $count === 1
            ? $this->articles[0]->title
            : __(':count nouvelles annonces', ['count' => $count]);

        return $security !== []
            ? '[DoliNews] '.__('Sécurité').' - '.$head
            : '[DoliNews] '.$head;
    }
}

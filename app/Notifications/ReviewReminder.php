<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Dolinews\Models\Article;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The three-day idle reminder (SPEC 5.1): towards the team for a
 * pending article, towards the author for requested changes. Internal
 * mechanism, never a public commitment.
 */
class ReviewReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Article $article,
        public readonly string $towards, // 'team' or 'author'
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
        $toTeam = $this->towards === 'team';

        return (new MailMessage)
            ->subject('[DoliNews] Relance de revue : '.$this->article->title)
            ->line($toTeam
                ? 'Cet article attend en file de revue sans activité depuis trois jours.'
                : 'Des modifications ont été demandées sur votre article sans resoumission de votre part.')
            ->action(
                $toTeam ? 'Ouvrir la file de revue' : 'Reprendre la rédaction',
                $toTeam
                    ? route('admin.review.show', $this->article)
                    : route('account.articles.edit', $this->article),
            )
            ->line('Aucun délai n\'est promis : cette relance est un mécanisme interne.');
    }
}

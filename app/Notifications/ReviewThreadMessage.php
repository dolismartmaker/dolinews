<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\ReviewMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Transactional email of the review circuit (SPEC 5.5): one per message
 * and per decision, to the author and the moderators.
 *
 * Nothing to do with subscription emails, which do not exist in phase
 * one (D11): these circuit emails exist from day one.
 *
 * Written in the recipient's language, which Laravel takes from their
 * preferredLocale(): author and moderators rarely share one (D14).
 */
class ReviewThreadMessage extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Article $article,
        public readonly ReviewMessage $message,
    ) {}

    /**
     * Mail only: the circuit lives in the back-office threads.
     *
     * @param  User  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $decision = $this->message->decision?->label() ?? null;
        $user = $this->message->user;
        $author = $user->display_name ?? $user->name ?? '-';

        return (new MailMessage)
            ->subject($decision !== null
                ? __('[DoliNews] Revue : :decision - :titre', [
                    'decision' => $decision,
                    'titre' => $this->article->title,
                ])
                : __('[DoliNews] Revue : nouveau message - :titre', [
                    'titre' => $this->article->title,
                ]))
            ->line(__(':auteur a écrit dans le fil de revue :', ['auteur' => $author]))
            ->line(mb_substr($this->message->body, 0, 500))
            ->action(__('Ouvrir le fil de revue'), route('admin.review.show', $this->article))
            ->line(__('Ce message fait partie du circuit de revue, il n\'est pas public.'));
    }
}

<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Dolinews\Models\Article;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Entry of an article in the review queue (SPEC 5.1), to the review
 * team. Sent on every submission, resubmissions included: a new round
 * voids the previous accords, so it does call for a fresh reading.
 *
 * Distinct from ReviewReminder, which nudges on an idle queue: this one
 * announces the arrival, no delay is promised either way.
 */
class ArticleSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Article $article,
        public readonly User $author,
    ) {}

    /**
     * @param  User  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->article->project;

        $message = (new MailMessage)
            ->subject('[DoliNews] Soumission en revue : '.$this->article->title)
            ->line($this->article->submission_seq > 1
                ? 'Un article revient en file de revue : les accords du tour précédent '
                    .'ne comptent plus.'
                : 'Un article vient d\'entrer en file de revue.')
            ->line('Titre : '.$this->article->title)
            ->line('Auteur : '.($this->author->display_name ?? $this->author->name))
            ->line('Éditeur : '.$this->article->editor->name);

        if ($project !== null) {
            $message->line('Projet : '.$project->name);
        }

        return $message
            ->line('Tour de revue : '.$this->article->submission_seq)
            ->action('Ouvrir le fil de revue', route('admin.review.show', $this->article))
            ->line('La publication demande trois accords de modérateurs distincts de '
                .'l\'auteur.');
    }
}

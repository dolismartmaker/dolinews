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
 *
 * Written in the recipient's language, which Laravel takes from their
 * preferredLocale(): the team is multilingual (D14), and telling a
 * moderator in French that a Spanish announcement awaits them serves
 * neither of the two.
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
            ->subject(__('[DoliNews] Soumission en revue : :titre', ['titre' => $this->article->title]))
            ->line($this->article->submission_seq > 1
                ? __('Un article revient en file de revue : les accords du tour précédent ne comptent plus.')
                : __('Un article vient d\'entrer en file de revue.'))
            ->line(__('Titre : :titre', ['titre' => $this->article->title]))
            ->line(__('Auteur : :auteur', [
                'auteur' => $this->author->display_name ?? $this->author->name,
            ]))
            ->line(__('Éditeur : :editeur', ['editeur' => $this->article->editor->name]))
            // Named rather than coded: "Español" tells a moderator what
            // es_ES does not, and this line exists precisely for the
            // reader who has to decide whether the entry is for them.
            ->line(__('Langue de l\'annonce : :langue', [
                'langue' => (string) (config('dolinews.locale_names')[User::baseLanguage((string) $this->article->locale)]
                    ?? $this->article->locale),
            ]));

        if ($project !== null) {
            $message->line(__('Projet : :projet', ['projet' => $project->name]));
        }

        $accords = $this->article->requiredAccords();

        return $message
            ->line(__('Tour de revue : :tour', ['tour' => (string) $this->article->submission_seq]))
            ->action(__('Ouvrir le fil de revue'), route('admin.review.show', $this->article))
            // The number is read from the article and never written in
            // the text: a translation takes one reviewer, not the quorum
            // (SPEC 5.1), and the quorum itself is configuration.
            ->line($accords > 1
                ? __('La publication demande :nombre accords de modérateurs distincts de l\'auteur.', [
                    'nombre' => (string) $accords,
                ])
                : __('La publication demande l\'accord d\'un relecteur.'));
    }
}

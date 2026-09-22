<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Dolinews\Models\ContentReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A published content was reported to the moderation team (SPEC 9.9).
 *
 * Sent on the first open report of a target only: the ones that follow
 * pile up in the queue without mailing again, so that one article cannot
 * be turned into a mail bomb aimed at the team.
 *
 * A circuit mail, like those of the review thread (SPEC 5.5): it answers
 * an act aimed at its recipients and carries no unsubscribe link, since
 * leaving it would mean leaving the moderation.
 */
class ContentReported extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ContentReport $report,
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
        $url = $this->report->targetUrl();

        $message = (new MailMessage)
            ->subject('[DoliNews] Signalement : '.$this->report->reason->label())
            ->line('Un visiteur signale un contenu publié à l\'équipe de modération.')
            ->line('Contenu : '.$this->report->targetLabel())
            ->line('Motif invoqué : '.$this->report->reason->label())
            // The language the report came in: the answer goes out in it,
            // and a moderator who does not read it hands the case over
            // rather than discovering the problem at the reply.
            ->line('Langue du signalant : '.$this->report->locale)
            ->line('Adresse du signalant : '.$this->report->reporter_email)
            ->line('Description :')
            ->line($this->report->body);

        if ($url !== null) {
            $message->line('Contenu signalé : '.$url);
        }

        return $message
            ->action('Ouvrir la file des signalements', route('admin.reports'))
            ->line('Un signalement ne vaut pas manquement : l\'acte éventuel se prend dans '
                .'le back-office, avec sa règle numérotée et son motif.');
    }
}

<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Dolinews\Enums\ProofMethod;
use App\Domain\Dolinews\Models\ContributorProof;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Operator notice of an account gaining write rights (SPEC 3.2/3.3), to
 * the super admins only, and only on the first proof: the event is the
 * passage to the contributor class, not each further commit address.
 *
 * The commit address never appears here, in any form: the service only
 * holds its peppered hash and never restores a clear address (SPEC 3.2,
 * docs/RGPD.md).
 */
class ContributorQualified extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly User $account,
        public readonly ContributorProof $proof,
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
        return (new MailMessage)
            ->subject('[DoliNews] Compte contributeur : '.$this->account->name)
            ->line('Un compte vient d\'être qualifié contributeur : il peut désormais '
                .'soumettre des articles.')
            ->line('Compte : '.$this->account->name.' ('.$this->account->email.')')
            ->line('Méthode de preuve : '.$this->method())
            ->line('Dépôt de référence : '.$this->proof->source_repo
                .' ('.$this->proof->commit_count.' commits)')
            ->action('Ouvrir la liste des comptes', route('admin.users'))
            ->line('Une preuve se révoque depuis le back-office, la révocation est '
                .'un acte de modération journalisé.');
    }

    /**
     * French label of the proof method, kept here rather than on the enum:
     * the enum carries the spec's storage values, this wording is the
     * operator's email only.
     */
    private function method(): string
    {
        return match ($this->proof->method) {
            ProofMethod::EMAIL => 'code à usage unique sur l\'adresse de commit',
            ProofMethod::GPG => 'défi signé GPG',
            ProofMethod::MANUAL => 'validation manuelle par l\'équipe de modération',
        };
    }
}

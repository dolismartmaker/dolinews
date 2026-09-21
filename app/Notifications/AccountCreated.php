<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Operator notice of a fresh reader account (SPEC 3.1), to the super
 * admins only: registration is free and grants no write right, so it
 * concerns the operator who watches the service, not the moderation
 * team who reviews articles.
 */
class AccountCreated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly User $account,
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
            ->subject('[DoliNews] Nouveau compte : '.$this->account->name)
            ->line('Un compte lecteur vient d\'être créé.')
            ->line('Nom : '.$this->account->name)
            ->line('Adresse : '.$this->account->email)
            ->action('Ouvrir la liste des comptes', route('admin.users'))
            ->line('Un compte lecteur n\'a aucun droit d\'écriture : la qualification '
                .'de contribution est une étape distincte, qui fait l\'objet de son '
                .'propre avis.');
    }
}

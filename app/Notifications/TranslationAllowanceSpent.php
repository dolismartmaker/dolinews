<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Dolinews\Models\Editor;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Told to an editor when the monthly translation allowance of the
 * service runs out for it (SPEC 5.7).
 *
 * SPEC 5.7 already requires the editor's own screen to state the
 * overrun, give the date it lifts and name the other routes. A screen
 * only reaches whoever opens it: an editor whose announcements stopped
 * being translated has no reason to go and look, and the versions it
 * expected in Spanish simply never appear. Hence this notice, sent once
 * per period and per editor.
 *
 * Three constraints shape the wording:
 *
 * - it is a state, never a breakage: the announcements stay published,
 *   distributed and filtered exactly as before, and an untranslated
 *   announcement is never penalised (SPEC 6.1). Saying so first is what
 *   keeps the mail from reading like a suspension notice;
 * - it names DeepL, because that is a supplier the editor deals with
 *   itself and it has to be told where to put the key. It never names
 *   the engine the service translates on: the repository is public and
 *   no interface of the service says it (SPEC 5.7);
 * - nothing here is for sale. Reaching the allowance opens no paid
 *   offer of the service (SPEC 12), so the mail offers two ways to keep
 *   going and no third one to pay for.
 *
 * Transactional, like the review-circuit mails: it answers what the
 * recipient's own announcements consumed, and carries no unsubscribe
 * link. Leaving it would mean not being told that the translation of
 * one's own announcements has stopped.
 */
class TranslationAllowanceSpent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Editor $editor,
        public readonly int $characters,
        public readonly int $ceiling,
        // Kept as a date and formatted in the view, where the locale of
        // the recipient is the one in force: formatted here, it would
        // carry the language of whatever job sent the mail.
        public readonly CarbonInterface $renewsOn,
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
            ->subject(__('[DoliNews] Volume de traduction du mois atteint : :editeur', [
                'editeur' => $this->editor->name,
            ]))
            ->markdown('mail.translation-allowance-spent', [
                'editor' => $this->editor,
                'characters' => $this->characters,
                'ceiling' => $this->ceiling,
                'renewsOn' => $this->renewsOn,
                // Where the editor puts its own DeepL key, and where the
                // API is documented: the two ways out, each behind the
                // link that actually leads to it.
                'editorUrl' => route('account.translations.automatic'),
                'apiUrl' => route('pages.api'),
            ]);
    }
}

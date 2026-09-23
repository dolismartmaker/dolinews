<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Translation;

use App\Domain\Dolinews\Enums\EditorRole;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\TranslationUsage;
use App\Models\User;
use App\Notifications\TranslationAllowanceSpent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Tells an editor that the shared monthly translation allowance is
 * spent for it (SPEC 5.7).
 *
 * SPEC 5.7 has the editor's screen state the overrun. A screen reaches
 * whoever opens it, and an editor whose Spanish versions stopped
 * appearing has no reason to go and look: the mail is what closes that
 * gap, and it names the two ways to keep going - the editor's own key,
 * or depositing translations through the API.
 *
 * Once per period and per editor, and that bound is the whole design of
 * this class. The allowance is hit again by every announcement
 * published for the rest of the month, so a notice sent at the point of
 * refusal would mean one mail per announcement: the editor stops
 * reading them, which is precisely how the service would lose the
 * address it needs the day a security announcement waits for review.
 *
 * The mark lives on the usage row (translation_usages.notified_at),
 * beside the counter it talks about, so a flushed cache cannot mail
 * anyone twice.
 */
class TranslationAllowanceNotice
{
    public function __construct(
        private readonly TranslationRouter $router,
    ) {}

    /**
     * Send the notice unless this editor already had it this month.
     *
     * Returns whether a mail actually went out, which is what a caller
     * reports and what a test asserts on.
     */
    public function send(Editor $editor): bool
    {
        // An editor on its own key draws on no allowance of ours: there
        // is nothing to tell it about, and telling it anyway would read
        // as an invitation to do what it already does.
        if (trim((string) ($editor->translation_api_key ?? '')) !== '') {
            return false;
        }

        $period = $this->router->period();

        /** @var TranslationUsage|null $usage */
        $usage = TranslationUsage::query()
            ->where('editor_id', $editor->getKey())
            ->where('period', $period)
            ->first();

        // No row means nothing was spent, so the allowance cannot be
        // spent either: whatever refused the translation, it was not
        // this.
        if ($usage === null || $usage->notified_at !== null) {
            return false;
        }

        $recipients = $this->recipients($editor);

        if ($recipients === []) {
            // Never silent: an editor nobody can write to is an
            // operating fact worth knowing, and the usage row is left
            // unmarked so a later run retries.
            Log::warning('TranslationAllowanceNotice: no address to warn', [
                'editor_id' => $editor->getKey(),
                'editor_slug' => $editor->slug,
                'period' => $period,
            ]);

            return false;
        }

        // Marked before sending, not after: a queue failure must not
        // turn into a mail per announcement for the rest of the month,
        // and the overrun is stated on the editor's screen anyway
        // (SPEC 5.7).
        $usage->notified_at = now();
        $usage->save();

        $notice = new TranslationAllowanceSpent(
            $editor,
            $usage->characters,
            $this->router->ceiling(),
            $this->renewsOn(),
        );

        Notification::send($recipients, $notice);

        Log::info('TranslationAllowanceNotice: editor warned of a spent allowance', [
            'editor_id' => $editor->getKey(),
            'period' => $period,
            'recipients' => count($recipients),
        ]);

        return true;
    }

    /**
     * Who is written to: the owners of the editor.
     *
     * The owner is the account that can actually act on what the mail
     * offers - it alone sets the key (SPEC 5.7) and grants a
     * translation mandate (SPEC 5.6). A member could do neither, so
     * telling them would hand a problem to someone with no way out of
     * it. Each owner is mailed in the language of its own account, the
     * User carrying its locale preference.
     *
     * @return array<int, User>
     */
    private function recipients(Editor $editor): array
    {
        /** @var array<int, User> $owners */
        $owners = $editor->users()
            ->wherePivot('role', EditorRole::OWNER->value)
            ->get()
            ->all();

        return $owners;
    }

    /**
     * The day the allowance is renewed: the first of next month, the
     * counter being per period. Returned as a date, the mail being the
     * one place that knows in which language to write it.
     */
    private function renewsOn(): CarbonInterface
    {
        return now()->addMonthNoOverflow()->startOfMonth();
    }
}

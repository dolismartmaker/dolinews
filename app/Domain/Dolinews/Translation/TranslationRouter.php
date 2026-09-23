<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Translation;

use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\TranslationUsage;
use Illuminate\Support\Facades\DB;

/**
 * Which engine translates for which editor, and what is left to spend
 * (SPEC 5.7).
 *
 * One rule, in this order:
 *
 * 1. the editor set its own key -> it translates on that key, with no
 *    ceiling of ours. It pays its own supplier and owes the service
 *    nothing, which is what keeps translation free here (SPEC 12);
 * 2. otherwise the shared route, within the monthly ceiling. The ceiling
 *    shares a common resource between editors; it sells nothing, and
 *    reaching it never turns into an invitation to pay the service.
 *
 * Reaching the ceiling is a state to be stated, never a breakage: the
 * editor's screen says so, gives the date it lifts, and offers the other
 * route.
 */
class TranslationRouter
{
    public const ROUTE_OWN_KEY = 'own_key';

    public const ROUTE_SHARED = 'shared';

    public const REASON_DISABLED = 'disabled';

    public const REASON_NO_ENGINE = 'no_engine';

    public const REASON_QUOTA_SPENT = 'quota_spent';

    /**
     * The engine that serves this editor, or null with the reason why
     * none does.
     *
     * $onDemand skips the editor's global opt-in, and only that: it is
     * the case of an editor asking for one announcement in one language
     * from its own screen. Clicking "translate into Spanish" on a given
     * announcement is a stronger consent than a checkbox ticked once,
     * so requiring the checkbox as well would refuse the clearer of the
     * two. Everything else - the engine, the key, the ceiling - applies
     * unchanged.
     *
     * $ignoreCeiling skips the monthly allowance, and only that. It
     * serves the operator's command line and nothing else: what the
     * ceiling protects is a resource of the operator's own, so the
     * operator may decide to spend past it - to finish a catalogue that
     * stopped mid-run, typically. What is spent is still counted, so
     * the next month starts from the truth. No interface and no API
     * point reaches it, which is what keeps SPEC 5.7 true: the ceiling
     * still opens no paid offer, and an editor cannot ask to be over
     * it.
     *
     * @return array{engine: TranslationEngine|null, route: string|null, reason: string|null}
     */
    public function resolve(Editor $editor, bool $onDemand = false, bool $ignoreCeiling = false): array
    {
        if (! $onDemand && ! $editor->auto_translate) {
            return ['engine' => null, 'route' => null, 'reason' => self::REASON_DISABLED];
        }

        $ownKey = (string) ($editor->translation_api_key ?? '');

        if (trim($ownKey) !== '') {
            return [
                'engine' => new DeepLEngine($ownKey),
                'route' => self::ROUTE_OWN_KEY,
                'reason' => null,
            ];
        }

        $shared = $this->sharedEngine();

        if (! $shared->isAvailable()) {
            return ['engine' => null, 'route' => null, 'reason' => self::REASON_NO_ENGINE];
        }

        if (! $ignoreCeiling && $this->remaining($editor) <= 0) {
            return ['engine' => null, 'route' => null, 'reason' => self::REASON_QUOTA_SPENT];
        }

        return ['engine' => $shared, 'route' => self::ROUTE_SHARED, 'reason' => null];
    }

    /**
     * The shared engine of the deployment, available or not.
     */
    public function sharedEngine(): TranslationEngine
    {
        // Through the container, where the deployment binds it once
        // (AppServiceProvider): that is also what lets a test stand a
        // fake engine in its place without faking the routing itself.
        return app(TranslationEngine::class);
    }

    /**
     * Characters this editor may still send on the shared route this
     * month. Meaningless for an editor on its own key, which draws on
     * nothing of ours.
     */
    public function remaining(Editor $editor): int
    {
        return max(0, $this->ceiling() - $this->spent($editor));
    }

    /**
     * Characters spent on the shared route this month.
     */
    public function spent(Editor $editor, ?string $period = null): int
    {
        /** @var TranslationUsage|null $usage */
        $usage = TranslationUsage::query()
            ->where('editor_id', $editor->getKey())
            ->where('period', $period ?? $this->period())
            ->first();

        return $usage === null ? 0 : $usage->characters;
    }

    /**
     * Book what was just sent on the shared route.
     *
     * Counted after the fact rather than reserved: an engine that
     * answers nothing costs the service nothing, and charging an editor
     * for a call that produced no text would spend a ceiling on a
     * failure it did not cause.
     */
    public function record(Editor $editor, int $characters): void
    {
        if ($characters <= 0) {
            return;
        }

        $period = $this->period();

        // Atomic: two translations of the same announcement may well be
        // running side by side on the queue.
        TranslationUsage::query()->firstOrCreate(
            ['editor_id' => $editor->getKey(), 'period' => $period],
            ['characters' => 0],
        );

        TranslationUsage::query()
            ->where('editor_id', $editor->getKey())
            ->where('period', $period)
            ->update(['characters' => DB::raw('characters + '.$characters)]);
    }

    /**
     * Monthly ceiling of the shared route, per editor.
     */
    public function ceiling(): int
    {
        return max(0, (int) config('dolinews.translation.monthly_characters', 120000));
    }

    /**
     * The current period, YYYY-MM.
     */
    public function period(): string
    {
        return now()->format('Y-m');
    }
}

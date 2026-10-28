<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Translation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The shared endpoint refused the operator's account, and is left alone
 * for a while.
 *
 * Its 402 is about the account, not about one request: every language of
 * every announcement would meet the same refusal, and a run split into
 * one job per language would send it nine times per announcement. Kept
 * in the cache, so every worker and every web request sees it, and lifted
 * by time: whoever tops the account up has nothing to undo here. The
 * operator's command lifts it at once.
 */
class SharedEndpointPause
{
    private const KEY = 'dolinews.translation.shared_endpoint_pause';

    public function pause(string $reason): void
    {
        $seconds = (int) config('dolinews.translation.refusal_pause', 3600);

        if ($seconds <= 0) {
            Log::notice('SharedEndpointPause: refused on the account state, pause disabled', [
                'reason' => $reason,
            ]);

            return;
        }

        Cache::put(self::KEY, $reason, $seconds);

        // Said once, for the operator: the editors are not the ones who
        // can top the account up.
        Log::warning('SharedEndpointPause: the shared endpoint refused the account, translation paused', [
            'reason' => $reason,
            'seconds' => $seconds,
        ]);
    }

    /**
     * The refusal the pause stands for, or null when there is none.
     */
    public function reason(): ?string
    {
        $reason = Cache::get(self::KEY);

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    public function lift(): void
    {
        Cache::forget(self::KEY);
    }
}

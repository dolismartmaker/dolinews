<?php

declare(strict_types=1);

namespace App\Core\Auth;

use Illuminate\Support\Carbon;

/**
 * The term of a freshly minted API token.
 *
 * Sanctum's `expiration` config alone would enforce the term without
 * ever writing it on the row, so neither the account page nor the API
 * could tell a holder when their token stops working. The term is
 * computed here and stored, from the same single setting.
 */
class TokenLifetime
{
    /**
     * When a token minted now must stop working, or null when the
     * configuration deliberately grants an endless one.
     */
    public static function expiresAt(): ?Carbon
    {
        $minutes = (int) config('sanctum.expiration', 0);

        if ($minutes <= 0) {
            return null;
        }

        return Carbon::now()->addMinutes($minutes);
    }
}

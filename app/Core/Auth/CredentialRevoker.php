<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cuts every credential an account holds apart from its password.
 *
 * Someone resetting a password is usually doing it because something
 * leaked. If the API tokens, the "remember me" cookie and the live web
 * sessions survive the reset, the reset changed nothing for whoever
 * holds them.
 */
class CredentialRevoker
{
    /**
     * Revoke API tokens, the remember cookie and the stored sessions.
     *
     * The account is NOT saved here: the caller is mid-save on the same
     * model, and the remember token must land in that same write.
     */
    public function revokeAllExceptPassword(User $user): void
    {
        $user->setRememberToken(Str::random(60));

        $user->tokens()->delete();

        $this->forgetStoredSessions($user);
    }

    /**
     * Drop the account's rows from the session store.
     *
     * Only the database driver keeps sessions where they can be found by
     * account; on any other driver the session lifetime is the bound,
     * and the reader is told so rather than left believing otherwise.
     */
    private function forgetStoredSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            Log::info('CredentialRevoker: sessions left in place, driver is not database', [
                'user_id' => $user->getKey(),
                'driver' => (string) config('session.driver'),
            ]);

            return;
        }

        try {
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        } catch (Throwable $e) {
            Log::error('CredentialRevoker: failed to drop stored sessions', [
                'user_id' => $user->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}

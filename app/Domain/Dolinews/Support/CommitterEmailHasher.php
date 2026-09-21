<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Support;

/**
 * Hashes and normalises contributor commit addresses (SPEC 3.2).
 *
 * Only sha256(pepper || normalised address) is ever stored: the service
 * must not host an exploitable clear list. The pepper lives in the
 * application environment, never in the database, and must never change.
 */
class CommitterEmailHasher
{
    /**
     * Normalise a commit address for hashing: trim, lowercase. Git
     * addresses are case-insensitive in practice at the domain and the
     * local part is folded by every major forge.
     */
    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * The peppered hash of a commit address.
     *
     * Throws when the pepper is not configured: silently hashing with an
     * empty pepper would create hashes that no later, correctly-peppered
     * lookup can ever find again.
     */
    public static function hash(string $email): string
    {
        $pepper = (string) config('dolinews.verification.pepper');

        if ($pepper === '') {
            throw new \RuntimeException(
                'DOLINEWS_COMMITTER_PEPPER is not configured; refusing to compute committer hashes.'
            );
        }

        return hash('sha256', $pepper.self::normalise($email));
    }

    /**
     * Whether an address is an anonymised forge redirect (*.noreply.*):
     * no email can ever be delivered there, so only the strong level or
     * manual validation remain possible for it (SPEC 3.3).
     */
    public static function isAnonymisedRedirect(string $email): bool
    {
        $normalised = self::normalise($email);

        return $normalised !== '' && str_contains($normalised, 'noreply');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Contributors;

use App\Domain\Dolinews\Models\KnownCommitterHash;
use App\Domain\Dolinews\Support\CommitterEmailHasher;
use Illuminate\Support\Facades\DB;

/**
 * Write access to known_committer_hashes, the observation index a
 * candidature is looked up in (SPEC 3.2).
 *
 * Shared by every feed of the index: the git harvest of a local clone
 * and the CSV import. Whatever the feed, only the peppered hash reaches
 * the table, never the clear address.
 */
class KnownCommitterIndex
{
    /**
     * Upsert one source's addresses into the index.
     *
     * A row is identified by (email_hash, source), so two sources
     * observing the same address stay two rows: the uniqueness rule of
     * SPEC 3.4 is enforced on proofs, not here.
     *
     * @param  array<string, int>  $countsByAddress  clear address => commit count
     * @return array{stored: int, updated: int}
     */
    public function upsert(array $countsByAddress, string $source): array
    {
        if ($countsByAddress === []) {
            return ['stored' => 0, 'updated' => 0];
        }

        $now = now();
        $stored = 0;
        $updated = 0;

        DB::transaction(function () use ($countsByAddress, $source, $now, &$stored, &$updated): void {
            foreach ($countsByAddress as $address => $commitCount) {
                $hash = CommitterEmailHasher::hash((string) $address);

                /** @var KnownCommitterHash|null $existing */
                $existing = KnownCommitterHash::query()
                    ->where('email_hash', $hash)
                    ->where('source_repo', $source)
                    ->first();

                if ($existing === null) {
                    KnownCommitterHash::query()->create([
                        'email_hash' => $hash,
                        'source_repo' => $source,
                        'commit_count' => $commitCount,
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                    ]);

                    $stored++;

                    continue;
                }

                $existing->commit_count = $commitCount;
                $existing->last_seen_at = $now;
                $existing->save();

                $updated++;
            }
        });

        return ['stored' => $stored, 'updated' => $updated];
    }
}

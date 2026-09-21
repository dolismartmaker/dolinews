<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Contributors;

use App\Domain\Dolinews\Models\KnownCommitterHash;
use App\Domain\Dolinews\Support\CommitterEmailHasher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Harvests commit authors from the local clones of the reference
 * repositories into known_committer_hashes (SPEC 3.2, D4).
 *
 * Works on plain git clones through the git binary: a repository is
 * distributable, and the verification must survive the forge
 * disappearing. No forge API is ever called.
 */
class CommitterHarvester
{
    /**
     * Harvest every configured repository.
     *
     * @return array{repos: int, hashes: int} per-run counters
     */
    public function harvestAll(): array
    {
        $repos = (array) config('dolinews.verification.repositories', []);
        $hashes = 0;

        foreach ($repos as $repo) {
            $hashes += $this->harvestRepository($repo);
        }

        return ['repos' => count($repos), 'hashes' => $hashes];
    }

    /**
     * Harvest one repository clone.
     *
     * Reads "author email<TAB>commit count" from the whole history, then
     * upserts one known_committer_hashes row per address. Only the
     * peppered hash is stored.
     */
    public function harvestRepository(string $repoPath): int
    {
        $repoPath = rtrim($repoPath, '/');

        if (! is_dir($repoPath.'/.git')) {
            Log::warning('CommitterHarvester: not a git repository, skipped', ['repo' => $repoPath]);

            return 0;
        }

        // Full commit author addresses with their commit counts. The
        // format string separates fields with a tab, which no mainstream
        // email address may contain.
        $result = Process::timeout(120)->run([
            'git', '-C', $repoPath, 'log', '--all',
            '--format=%ae%x09%h', '--no-mailmap',
        ]);

        if (! $result->successful()) {
            Log::error('CommitterHarvester: git log failed', [
                'repo' => $repoPath,
                'error' => trim($result->errorOutput()),
            ]);

            return 0;
        }

        $counts = [];
        foreach (explode("\n", trim($result->output())) as $line) {
            $line = trim($line);

            if ($line === '' || ! str_contains($line, "\t")) {
                continue;
            }

            [$email] = explode("\t", $line, 2);
            $email = CommitterEmailHasher::normalise($email);

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $counts[$email] = ($counts[$email] ?? 0) + 1;
        }

        if ($counts === []) {
            Log::info('CommitterHarvester: no commit author found', ['repo' => $repoPath]);

            return 0;
        }

        $now = now();
        $stored = 0;

        foreach ($counts as $email => $commitCount) {
            $hash = CommitterEmailHasher::hash($email);

            /** @var KnownCommitterHash|null $existing */
            $existing = KnownCommitterHash::query()
                ->where('email_hash', $hash)
                ->where('source_repo', $repoPath)
                ->first();

            if ($existing === null) {
                KnownCommitterHash::query()->create([
                    'email_hash' => $hash,
                    'source_repo' => $repoPath,
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
        }

        Log::info('CommitterHarvester: repository harvested', [
            'repo' => $repoPath,
            'authors' => count($counts),
            'new_hashes' => $stored,
        ]);

        return $stored;
    }
}

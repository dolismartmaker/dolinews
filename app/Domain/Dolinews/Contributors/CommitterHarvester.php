<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Contributors;

use App\Domain\Dolinews\Support\CommitterEmailHasher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

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
    public function __construct(
        private readonly KnownCommitterIndex $index = new KnownCommitterIndex,
    ) {}

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

        $upsert = $this->index->upsert($counts, $repoPath);
        $keys = $this->index->upsertKeys($this->signingKeys($repoPath), $repoPath);

        Log::info('CommitterHarvester: repository harvested', [
            'repo' => $repoPath,
            'authors' => count($counts),
            'new_hashes' => $upsert['stored'],
            'new_signing_keys' => $keys,
        ]);

        return $upsert['stored'];
    }

    /**
     * Fingerprints of the OpenPGP keys that signed commits, per author
     * address (SPEC 3.2, strong level).
     *
     * Read from the issuer fingerprint every modern signature carries,
     * which gpg prints even without the public key: the harvest runs in
     * an empty throwaway keyring, so it neither needs nor fetches any
     * key, and no forge or keyserver is ever contacted. A signature that
     * only names a short or long key id (gpg 1.x) is ignored: an id is
     * not a fingerprint, and such an address keeps the manual path.
     *
     * @return array<string, list<string>> normalised address => fingerprints
     */
    private function signingKeys(string $repoPath): array
    {
        $home = (string) realpath(sys_get_temp_dir()).'/dolinews-harvest-gpg-'.Str::random(12);

        if (! @mkdir($home, 0700)) {
            Log::error('CommitterHarvester: could not create the throwaway keyring, signing keys skipped', [
                'repo' => $repoPath,
                'path' => $home,
            ]);

            return [];
        }

        try {
            // Records split by RS, author and raw gpg output by US: the
            // gpg output spans several lines. C locale keeps its wording
            // stable for the parse below.
            $result = Process::env(['GNUPGHOME' => $home, 'LC_ALL' => 'C', 'LANG' => 'C'])
                ->timeout((int) config('dolinews.verification.signature_harvest_timeout', 900))
                ->run([
                    'git', '-C', $repoPath, 'log', '--all',
                    '--format=%x1e%ae%x1f%GG', '--no-mailmap',
                ]);
        } finally {
            $this->removeDirectory($home);
        }

        if (! $result->successful()) {
            Log::error('CommitterHarvester: signature scan failed, signing keys skipped', [
                'repo' => $repoPath,
                'error' => trim($result->errorOutput()),
            ]);

            return [];
        }

        $keys = [];
        foreach (explode("\x1e", $result->output()) as $record) {
            if (! str_contains($record, "\x1f")) {
                continue;
            }

            [$email, $raw] = explode("\x1f", $record, 2);

            if (preg_match_all('/using \S+ key ([0-9A-F]{64}|[0-9A-F]{40})\b/', $raw, $m) < 1) {
                continue;
            }

            $email = CommitterEmailHasher::normalise($email);

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            foreach ($m[1] as $fingerprint) {
                $keys[$email][$fingerprint] = true;
            }
        }

        return array_map(static fn (array $set): array => array_keys($set), $keys);
    }

    /**
     * Wipe the throwaway keyring, whatever gpg left in it.
     */
    private function removeDirectory(string $path): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if (! $entry instanceof \SplFileInfo) {
                continue;
            }

            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        if (! @rmdir($path)) {
            Log::warning('CommitterHarvester: throwaway keyring left on disk', ['path' => $path]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Contributors;

use App\Domain\Dolinews\Support\CommitterEmailHasher;
use Illuminate\Support\Facades\Log;

/**
 * Feeds known_committer_hashes from a flat address list extracted
 * beforehand from the reference repositories (SPEC 3.2).
 *
 * Same index, same peppered hashes and same lookup as the git harvest:
 * only the acquisition channel differs. The point is to spare the
 * production host a multi-hundred-megabyte clone it would only ever read
 * commit metadata from; the extraction itself still happens on a real
 * repository, never through a forge API (SPEC D4).
 *
 * The file is a snapshot, therefore dated: it must be regenerated for
 * new contributors to become known.
 */
class CommitterCsvImporter
{
    public function __construct(
        private readonly KnownCommitterIndex $index = new KnownCommitterIndex,
    ) {}

    /**
     * Import one file into the index under the given source label.
     *
     * Accepts anything shaped like an address list: one address per
     * line, optionally followed by a commit count, separated by a comma,
     * a semicolon or a tab. Surrounding quotes, blank lines, a header row
     * and any cell that is not a valid address are dropped without
     * failing the run: extractions are hand-made, they carry noise.
     *
     * @return array{lines: int, addresses: int, skipped: int, stored: int, updated: int}
     */
    public function import(string $path, string $source): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            Log::error('CommitterCsvImporter: file unreadable', ['file' => $path]);

            throw new \RuntimeException('Fichier illisible : '.$path);
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            Log::error('CommitterCsvImporter: file could not be opened', ['file' => $path]);

            throw new \RuntimeException('Fichier impossible à ouvrir : '.$path);
        }

        $counts = [];
        $lines = 0;
        $skipped = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $lines++;

                $parsed = $this->parseLine($line);

                if ($parsed === null) {
                    $skipped++;

                    continue;
                }

                [$address, $commitCount] = $parsed;

                // The same address may appear several times in a
                // hand-made extraction: keep the highest count seen.
                $counts[$address] = max($counts[$address] ?? 0, $commitCount);
            }
        } finally {
            fclose($handle);
        }

        if ($counts === []) {
            Log::warning('CommitterCsvImporter: no valid address found', [
                'file' => $path,
                'lines' => $lines,
            ]);

            return ['lines' => $lines, 'addresses' => 0, 'skipped' => $skipped, 'stored' => 0, 'updated' => 0];
        }

        $upsert = $this->index->upsert($counts, $source);

        Log::info('CommitterCsvImporter: file imported', [
            'file' => $path,
            'source' => $source,
            'addresses' => count($counts),
            'skipped' => $skipped,
            'stored' => $upsert['stored'],
        ]);

        return [
            'lines' => $lines,
            'addresses' => count($counts),
            'skipped' => $skipped,
            'stored' => $upsert['stored'],
            'updated' => $upsert['updated'],
        ];
    }

    /**
     * Extract [normalised address, commit count] from one raw line, or
     * null when the line carries no address.
     *
     * A missing count means one observed commit, never zero: the address
     * was read off a real history, and zero would read as "known but
     * never committed".
     *
     * @return array{0: string, 1: int}|null
     */
    private function parseLine(string $line): ?array
    {
        $cells = preg_split('/[,;\t]/', trim($line, " \t\r\n"));

        if ($cells === false || $cells === []) {
            return null;
        }

        $address = CommitterEmailHasher::normalise(trim($cells[0], " \t\"'"));

        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        // First fully numeric cell after the address is the commit count,
        // wherever the extraction put it: a name column may well sit in
        // between.
        $count = 1;

        foreach (array_slice($cells, 1) as $cell) {
            $cell = trim($cell, " \t\"'");

            if ($cell !== '' && ctype_digit($cell)) {
                $count = max(1, (int) $cell);

                break;
            }
        }

        return [$address, $count];
    }
}

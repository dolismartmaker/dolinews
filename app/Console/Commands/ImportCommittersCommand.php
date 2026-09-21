<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dolinews\Contributors\CommitterCsvImporter;
use App\Domain\Dolinews\Contributors\ContributorVerificationService;
use Illuminate\Console\Command;

/**
 * Import a contributor address list into known_committer_hashes
 * (SPEC 3.2), as an alternative to harvesting a local clone.
 *
 * The file is extracted beforehand from the reference repositories; this
 * spares the production host a full clone it would only read commit
 * metadata from. Only the peppered hash ever reaches the database.
 */
class ImportCommittersCommand extends Command
{
    protected $signature = 'dolinews:import-committers
        {file : Path to the address list (one address per line, optional commit count)}
        {--source= : Source label stored with the hashes, defaults to csv:<filename>}
        {--link-accounts : Qualify already verified accounts whose address is in the index}';

    protected $description = 'Import a contributor address list into the known committer index';

    public function handle(
        CommitterCsvImporter $importer,
        ContributorVerificationService $verification,
    ): int {
        $file = (string) $this->argument('file');
        $source = (string) $this->option('source');

        if ($source === '') {
            $source = 'csv:'.pathinfo($file, PATHINFO_FILENAME);
        }

        try {
            $stats = $importer->import($file, $source);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d lines read, %d addresses kept, %d skipped, %d new hashes, %d refreshed (source: %s).',
            $stats['lines'],
            $stats['addresses'],
            $stats['skipped'],
            $stats['stored'],
            $stats['updated'],
            $source,
        ));

        if ($stats['addresses'] === 0) {
            $this->warn('No valid address in this file, the index is unchanged.');

            return self::FAILURE;
        }

        if ($this->option('link-accounts') === true) {
            $linked = $verification->linkVerifiedAccounts();

            $this->info("{$linked} accounts qualified from their verified address.");
        }

        return self::SUCCESS;
    }
}

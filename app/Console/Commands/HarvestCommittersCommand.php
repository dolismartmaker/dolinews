<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dolinews\Contributors\CommitterHarvester;
use Illuminate\Console\Command;

/**
 * Harvest the commit authors of the reference repositories into
 * known_committer_hashes (SPEC 3.2, D4).
 *
 * Runs over local git clones only: a repository is distributable, and
 * the verification must survive the forge disappearing. No forge API
 * is ever called.
 */
class HarvestCommittersCommand extends Command
{
    protected $signature = 'dolinews:harvest-committers {--repo= : Harvest one repository path instead of the configured list}';

    protected $description = 'Harvest commit author hashes from the reference git repositories';

    public function handle(CommitterHarvester $harvester): int
    {
        if ((string) $this->option('repo') !== '') {
            $new = $harvester->harvestRepository((string) $this->option('repo'));
            $this->info("Repo harvested, {$new} new hashes.");

            return self::SUCCESS;
        }

        $stats = $harvester->harvestAll();

        if ($stats['repos'] === 0) {
            $this->warn('No reference repository configured (DOLINEWS_REFERENCE_REPOS).');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Harvested %d repositories, %d new hashes.',
            $stats['repos'],
            $stats['hashes'],
        ));

        return self::SUCCESS;
    }
}

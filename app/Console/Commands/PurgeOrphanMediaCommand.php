<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dolinews\Media\MediaService;
use Illuminate\Console\Command;

/**
 * Purge orphan media beyond the grace period (SPEC 5.2): uploaded but
 * never referenced by a created article.
 */
class PurgeOrphanMediaCommand extends Command
{
    protected $signature = 'dolinews:purge-orphan-media';

    protected $description = 'Purge orphan media older than the grace period';

    public function handle(MediaService $media): int
    {
        $count = $media->purgeOrphans();

        $this->info("Purged {$count} orphan media.");

        return self::SUCCESS;
    }
}

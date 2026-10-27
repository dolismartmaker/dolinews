<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dolinews\Models\Attestation;
use App\Domain\Dolinews\Models\ProjectLink;
use App\Domain\Dolinews\Projects\LinkProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodic link check of the PROJECT sheets (SPEC 8): marks broken
 * links so a sheet stops pointing at nothing.
 *
 * The reports behind the test attestations are checked too (SPEC 10):
 * an indicator is worth the report a reader can open, and a CI run
 * expires with its artifacts.
 *
 * The links of OLD ARTICLES are not checked: a journal dates, dead
 * links inside dated entries are normal.
 *
 * The addresses come from contributors, so the probe itself is guarded
 * against server-side request forgery: see LinkProbe and
 * OutboundUrlGuard.
 */
class CheckProjectLinksCommand extends Command
{
    protected $signature = 'dolinews:check-links {--limit=100 : Maximum links per run, for each kind}';

    protected $description = 'Check project sheet links and attestation reports, and mark the broken ones';

    public function handle(LinkProbe $probe): int
    {
        $timeout = (int) config('dolinews.links.check_timeout', 10);
        $limit = max(1, (int) $this->option('limit'));

        // Oldest checked first: every link gets looked at eventually.
        $links = ProjectLink::query()
            ->orderBy('checked_at')
            ->limit($limit)
            ->get();

        $broken = 0;

        foreach ($links as $link) {
            $isBroken = $this->isBroken($probe, $link->url, $timeout, ['link_id' => $link->getKey()]);

            $link->checked_at = now();
            $link->is_broken = $isBroken;
            $link->save();

            if ($isBroken) {
                $broken++;
            }
        }

        $this->info(sprintf('Checked %d links, %d broken.', $links->count(), $broken));

        $attestations = Attestation::query()
            ->orderBy('checked_at')
            ->limit($limit)
            ->get();

        $broken = 0;

        foreach ($attestations as $attestation) {
            $isBroken = $this->isBroken($probe, $attestation->source_url, $timeout, ['attestation_id' => $attestation->getKey()]);

            $attestation->checked_at = now();
            $attestation->is_broken = $isBroken;
            $attestation->save();

            if ($isBroken) {
                $broken++;
            }
        }

        $this->info(sprintf('Checked %d attestation reports, %d broken.', $attestations->count(), $broken));

        return self::SUCCESS;
    }

    /**
     * Probe one address; a request that fails outright counts as broken.
     *
     * @param  array<string, mixed>  $context  identifies the row in the log
     */
    private function isBroken(LinkProbe $probe, string $url, int $timeout, array $context): bool
    {
        try {
            $result = $probe->check($url, $timeout);
        } catch (\Throwable $e) {
            Log::info('CheckProjectLinks: request failed', $context + ['error' => $e->getMessage()]);

            return true;
        }

        if ($result['broken']) {
            Log::info('CheckProjectLinks: link marked broken', $context + ['reason' => $result['reason']]);
        }

        return $result['broken'];
    }
}

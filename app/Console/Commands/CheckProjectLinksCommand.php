<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dolinews\Models\ProjectLink;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Periodic link check of the PROJECT sheets (SPEC 8): marks broken
 * links so a sheet stops pointing at nothing.
 *
 * The links of OLD ARTICLES are not checked: a journal dates, dead
 * links inside dated entries are normal.
 */
class CheckProjectLinksCommand extends Command
{
    protected $signature = 'dolinews:check-links {--limit=100 : Maximum links per run}';

    protected $description = 'Check project sheet links and mark the broken ones';

    public function handle(): int
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
            try {
                // HEAD first, GET fallback: some shops reject HEAD.
                $response = Http::timeout($timeout)
                    ->withHeaders(['User-Agent' => 'DoliNews-LinkCheck/1.0'])
                    ->head($link->url);

                if ($response->status() === 405 || $response->status() === 501) {
                    $response = Http::timeout($timeout)
                        ->withHeaders(['User-Agent' => 'DoliNews-LinkCheck/1.0'])
                        ->get($link->url);
                }

                $isBroken = $response->status() >= 400;
            } catch (\Throwable $e) {
                Log::info('CheckProjectLinks: request failed', [
                    'link_id' => $link->getKey(),
                    'error' => $e->getMessage(),
                ]);

                $isBroken = true;
            }

            $link->checked_at = now();
            $link->is_broken = $isBroken;
            $link->save();

            if ($isBroken) {
                $broken++;
            }
        }

        $this->info(sprintf('Checked %d links, %d broken.', $links->count(), $broken));

        return self::SUCCESS;
    }
}

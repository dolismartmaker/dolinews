<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dolinews\Releases\ReleaseWatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily watch of the Dolibarr core releases (SPEC 5.8).
 *
 * The core is the one project nobody announces here: no third-party
 * editor owns it, and the integrator who has to apply a security fix
 * learns about it from a forge they do not watch. The watch fills that
 * gap, and it fills it the way everything else is filled - by submitting
 * to the review, never by publishing (D6).
 *
 * Safe to re-run: a version already announced is skipped whatever
 * became of the article, and a quota refusal consumes nothing. An
 * interrupted run is resumed by running it again.
 *
 * --since is the catch-up, and the only way to reach back before the
 * starting point the first run recorded: an operator setting the watch
 * up on an existing instance uses it once, with a date they chose.
 */
class WatchDolibarrReleasesCommand extends Command
{
    protected $signature = 'dolinews:watch-dolibarr-releases
        {--since= : Floor date (YYYY-MM-DD), explicit catch-up}
        {--limit= : Maximum announcements submitted in this run}
        {--dry-run : Write nothing, state what would be submitted}';

    protected $description = 'Submit an announcement for every new stable Dolibarr release';

    public function handle(ReleaseWatchService $watch): int
    {
        $since = null;

        if ((string) $this->option('since') !== '') {
            try {
                $since = Carbon::parse((string) $this->option('since'));
            } catch (\Throwable $e) {
                $this->error('Date --since illisible : '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $limit = (string) $this->option('limit') !== ''
            ? max(1, (int) $this->option('limit'))
            : null;

        $result = $watch->run($since, $limit, (bool) $this->option('dry-run'));

        foreach ($result['submitted'] as $version) {
            $this->line(($this->option('dry-run') ? 'À soumettre : ' : 'Soumis : ').'Dolibarr '.$version);
        }

        if ($result['skipped'] !== []) {
            $this->line('Déjà annoncées : '.implode(', ', $result['skipped']));
        }

        // A stop is said out loud, including the ones that are not
        // failures: an operator who sees nothing happen for a week must
        // be able to tell an idle watch from a full review queue.
        $status = match ($result['stopped']) {
            'no_project' => 'Aucune fiche projet exploitable : veille inactive.',
            'no_author' => 'Aucun compte de soumission exploitable : veille inactive.',
            'first_run' => 'Premier passage : point de départ enregistré, rien de soumis.',
            'queue_ceiling' => 'Plafond de file atteint : la suite passera quand la revue aura avancé.',
            'bucket_empty' => 'Jetons de publication épuisés : la suite passera avec le temps.',
            default => null,
        };

        if ($status !== null) {
            $this->info($status);
        }

        $this->info(sprintf(
            '%d annonce(s) soumise(s), %d ignorée(s).',
            count($result['submitted']),
            count($result['skipped']),
        ));

        // A misconfiguration is a failure the operator has to see; an
        // unconfigured watch is an intended state and stays silent.
        if (in_array($result['stopped'], ['no_project', 'no_author'], true)
            && (string) config('dolinews.releases.project', '') !== ''
            && (string) config('dolinews.releases.author_email', '') !== '') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

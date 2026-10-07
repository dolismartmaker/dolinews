<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Releases;

use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Articles\QuotaException;
use App\Domain\Dolinews\Enums\ArticleType;
use App\Domain\Dolinews\Enums\CompatStatus;
use App\Domain\Dolinews\Enums\Maturity;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\ServiceState;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Watch of the Dolibarr core releases (SPEC 5.8).
 *
 * The one thing this service does NOT do is publish. It submits, like
 * any editor with a token does, and the review decides (D6): a text
 * nobody read must not reach the feed, all the less so when it carries a
 * security fix and mails every subscriber who asked for those. The
 * operator stays the editor of what appears (SPEC 9.7), and staying it
 * means reading first.
 *
 * Two bounds keep a daily task from flooding the queue:
 *
 * - the starting point is written once, on the first run, and no release
 *   older than it is ever submitted. Without it, the first run turns the
 *   whole history the feed carries into pending articles;
 * - a quota refusal stops the run where it stands and consumes nothing:
 *   the release stays unannounced, and tomorrow's run picks it up. Both
 *   refusals are legitimate here - five announcements in review is the
 *   ceiling the reviewers set for themselves (SPEC 5.3), and this task
 *   is not entitled to more room than an editor.
 */
class ReleaseWatchService
{
    /**
     * Instant the watch started. Written once and never moved: it is the
     * boundary between the history the feed carries and what the service
     * has taken responsibility for announcing.
     */
    public const STARTED_KEY = 'dolibarr_release_watch_started_at';

    public function __construct(
        private readonly ReleaseFeedClient $feed,
        private readonly ReleaseWriter $writer,
        private readonly ArticleService $articles,
    ) {}

    /**
     * Read the feed and submit what has not been announced yet.
     *
     * @param  CarbonInterface|null  $since  explicit catch-up bound, the
     *                                       stored starting point otherwise
     * @return array{submitted: array<int, string>, skipped: array<int, string>, stopped: string|null}
     */
    public function run(?CarbonInterface $since = null, ?int $limit = null, bool $dryRun = false): array
    {
        $result = ['submitted' => [], 'skipped' => [], 'stopped' => null];

        $project = $this->project();

        if ($project === null) {
            $result['stopped'] = 'no_project';

            return $result;
        }

        $editor = $project->editor;

        if ($editor === null) {
            Log::warning('ReleaseWatch: the configured sheet has no editor', [
                'slug' => $project->slug,
            ]);

            $result['stopped'] = 'no_project';

            return $result;
        }

        $author = $this->author();

        if ($author === null) {
            $result['stopped'] = 'no_author';

            return $result;
        }

        // Read, and written on the first run, whatever --since says: an
        // operator who catches up first and lets the scheduler take over
        // afterwards must not see the first scheduled run set the
        // boundary to that later day, which would bury every release
        // published in between.
        $stored = $this->startingPoint($dryRun);
        $bound = $since ?? $stored;

        if ($bound === null) {
            // First run: the boundary was just written, and everything
            // the feed carries predates it.
            Log::info('ReleaseWatch: starting point recorded, nothing submitted on the first run');

            $result['stopped'] = 'first_run';

            return $result;
        }

        $locale = (string) config('dolinews.releases.locale', 'fr_FR');
        $limit = $limit ?? (int) config('dolinews.releases.max_per_run', 3);
        $releases = $this->feed->fetch();

        // Oldest first: a run that stops on a quota refusal leaves the
        // most recent ones behind, which are the ones tomorrow's run
        // still finds in the feed. The reverse loses the oldest.
        $releases = array_reverse($releases);

        foreach ($releases as $release) {
            if (count($result['submitted']) >= max(1, $limit)) {
                break;
            }

            if ($release->releasedAt->lessThanOrEqualTo($bound)) {
                continue;
            }

            if ($this->alreadyKnown($project, $release)) {
                $result['skipped'][] = $release->version();

                continue;
            }

            if ($dryRun) {
                $result['submitted'][] = $release->version();

                continue;
            }

            try {
                $article = $this->submit($project, $editor, $author, $release, $locale);
            } catch (QuotaException $e) {
                // Not a failure: the queue is full or the bucket is
                // empty, and both clear on their own (SPEC 5.3). The
                // release is left untouched for the next run.
                Log::notice('ReleaseWatch: quota refused the submission, run stopped', [
                    'version' => $release->version(),
                    'queue_ceiling' => $e->queueCeiling,
                    'reason' => $e->getMessage(),
                ]);

                $result['stopped'] = $e->queueCeiling ? 'queue_ceiling' : 'bucket_empty';

                break;
            }

            $result['submitted'][] = $release->version();

            Log::info('ReleaseWatch: release submitted to review', [
                'article_id' => $article->getKey(),
                'version' => $release->version(),
                'focus' => $release->focus()->value,
            ]);
        }

        return $result;
    }

    /**
     * Write the announcement and submit it, draft and submission in one
     * transaction.
     *
     * One transaction for the reason POST /articles has one: a quota
     * refusal must not leave the draft behind, or the next run submits a
     * second copy of the same release the day the queue clears.
     */
    private function submit(
        Project $project,
        Editor $editor,
        User $author,
        DolibarrRelease $release,
        string $locale,
    ): Article {
        $written = $this->writer->write($release, $locale)
            ?? (new MechanicalReleaseWriter)->write($release, $locale);

        // The mechanical writer never returns null; the null check is
        // what tells PHPStan so, and what would catch a future writer
        // used as the fallback.
        if ($written === null) {
            throw new \RuntimeException('Aucun texte produit pour la version '.$release->version().'.');
        }

        return DB::transaction(function () use ($project, $editor, $author, $release, $locale, $written): Article {
            $article = $this->articles->createDraft($author, $editor, [
                'project_id' => $project->getKey(),
                'type' => ArticleType::RELEASE,
                'focus' => $release->focus(),
                'title' => $written->title,
                'summary' => $written->summary,
                'body' => $written->body,
                'version' => $release->version(),
                'locale' => $locale,
                // The announcement concerns the core itself, so the range
                // is the released major and nothing else: the reader
                // filtering the feed on "concerne Dolibarr 21" is asking
                // exactly this question (SPEC 6.1).
                'dolibarr_min' => $release->major,
                'dolibarr_max' => $release->major,
                'maturity' => Maturity::STABLE->value,
                'compat_status' => CompatStatus::DECLARED->value,
            ]);

            // Said before the review reads it, and kept afterwards: the
            // article carries the mention publicly (SPEC 5.8).
            $article->auto_drafted = true;
            $article->save();

            return $this->articles->submit($article, $author);
        });
    }

    /**
     * Whether this version already has an article on the sheet, whatever
     * its state.
     *
     * Deliberately blind to the status: a rejected or hidden article is
     * a decision that was taken about this release, and resubmitting it
     * the next morning would make the watch argue with the team.
     */
    private function alreadyKnown(Project $project, DolibarrRelease $release): bool
    {
        return Article::query()
            ->where('project_id', $project->getKey())
            ->where('version', $release->version())
            ->exists();
    }

    /**
     * The starting point of the watch, null on the run that writes it.
     */
    private function startingPoint(bool $dryRun): ?Carbon
    {
        $stored = ServiceState::read(self::STARTED_KEY);

        if ($stored !== null) {
            return Carbon::parse($stored);
        }

        if ($dryRun) {
            // A dry run states what a real run would do and changes
            // nothing: on a fresh instance, that is nothing at all.
            return null;
        }

        ServiceState::writeOnce(self::STARTED_KEY, now()->toIso8601String());

        return null;
    }

    /**
     * The project sheet of the core, null when it is not set up.
     *
     * Never created here: a sheet is an editorial act, and a scheduled
     * task that creates one on a mistyped slug leaves a ghost project
     * behind on a Sunday morning.
     */
    private function project(): ?Project
    {
        $slug = (string) config('dolinews.releases.project', '');

        if ($slug === '') {
            Log::notice('ReleaseWatch: no project sheet configured, watch idle');

            return null;
        }

        $project = Project::query()->where('slug', $slug)->first();

        if ($project === null) {
            Log::warning('ReleaseWatch: configured project sheet not found', ['slug' => $slug]);
        }

        return $project;
    }

    /**
     * The account the announcements are submitted under.
     */
    private function author(): ?User
    {
        $email = (string) config('dolinews.releases.author_email', '');

        if ($email === '') {
            Log::notice('ReleaseWatch: no submitting account configured, watch idle');

            return null;
        }

        $author = User::query()->where('email', $email)->first();

        if ($author === null) {
            Log::warning('ReleaseWatch: configured submitting account not found', ['email' => $email]);
        }

        return $author;
    }
}

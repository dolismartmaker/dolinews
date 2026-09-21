<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Review\ReviewException;
use App\Domain\Dolinews\Review\ReviewService;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Publish articles already in review under the date of the version they
 * announce (SPEC 5.1).
 *
 * The companion of scripts/publish-caprel-catalog.php, which submits the
 * catalogue through the API and cannot publish anything: a token grants
 * the right to submit, never to publish. This command runs on the
 * instance, where the super admin's derogation lives.
 *
 * It reads that script's manifest for the pairing it already holds:
 * submitted_article_id, and the first-version date to publish under.
 * Every publication goes through ReviewService, so the derogation stays
 * journalled with its motive and its date, the bootstrap ceiling keeps
 * counting, and the refusal on the admin's own content outside the
 * bootstrap phase still applies.
 */
class PublishBackdatedCommand extends Command
{
    protected $signature = 'dolinews:publish-backdated
        {manifest : Path to the manifest written by publish-caprel-catalog.php}
        {--admin= : Email of the publishing super admin, defaults to the only one}
        {--motive=Publication antidatée du catalogue : Motive recorded in the moderation journal}
        {--dry-run : Report what would be published, change nothing}';

    protected $description = 'Publish submitted articles under their first-version date';

    public function handle(ReviewService $review): int
    {
        $entries = $this->readManifest();

        if ($entries === null) {
            return self::FAILURE;
        }

        $admin = $this->resolveAdmin();

        if ($admin === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $motive = (string) $this->option('motive');
        $published = 0;
        $skipped = 0;

        foreach ($entries as $slug => $entry) {
            $article = $this->resolveArticle($slug, $entry);

            if ($article === null) {
                $skipped++;

                continue;
            }

            $date = $this->resolveDate($slug, $entry);

            if ($date === null) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '  %s : article #%d publierait au %s',
                    $slug,
                    $article->getKey(),
                    $date->format('Y-m-d'),
                ));
                $published++;

                continue;
            }

            try {
                $review->publishByAdmin($article, $admin, $motive, $date);
            } catch (ReviewException $e) {
                // Never silent: a refused derogation is exactly what the
                // operator needs to read, and the run carries on with the
                // rest rather than abandoning what it could still do.
                $this->error(sprintf('  %s : refusé, %s', $slug, $e->getMessage()));
                $skipped++;

                continue;
            }

            $this->line(sprintf(
                '  %s : article #%d publié au %s',
                $slug,
                $article->getKey(),
                $date->format('Y-m-d'),
            ));
            $published++;
        }

        $this->info(sprintf(
            '%d articles %s, %d ignorés.',
            $published,
            $dryRun ? 'à publier' : 'publiés',
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * The manifest entries, or null when it cannot be read.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function readManifest(): ?array
    {
        $path = (string) $this->argument('manifest');

        if (! is_readable($path)) {
            $this->error('Manifeste introuvable ou illisible : '.$path);

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            $this->error('Manifeste mal formé : '.$path);

            return null;
        }

        return $decoded;
    }

    /**
     * The publishing super admin: the one named, or the only one there
     * is. Several without --admin is an ambiguity the command refuses to
     * resolve by itself, the choice being journalled as an act.
     */
    private function resolveAdmin(): ?User
    {
        $email = (string) $this->option('admin');

        if ($email !== '') {
            /** @var User|null $named */
            $named = User::query()->where('email', $email)->first();

            if ($named === null || ! $named->is_super_admin) {
                $this->error('Aucun super administrateur ne porte cette adresse : '.$email);

                return null;
            }

            return $named;
        }

        $admins = User::query()->where('is_super_admin', true)->get();

        if ($admins->count() !== 1) {
            $this->error($admins->isEmpty()
                ? 'Aucun super administrateur en base.'
                : 'Plusieurs super administrateurs : précisez --admin=<courriel>.');

            return null;
        }

        /** @var User $only */
        $only = $admins->first();

        return $only;
    }

    /**
     * The article a manifest entry points at, when it is still awaiting
     * review. Anything else is reported and left alone.
     *
     * @param  array<string, mixed>  $entry
     */
    private function resolveArticle(string $slug, array $entry): ?Article
    {
        $id = $entry['submitted_article_id'] ?? null;

        if (! is_int($id)) {
            return null;
        }

        /** @var Article|null $article */
        $article = Article::query()->find($id);

        if ($article === null) {
            $this->warn(sprintf('  %s : article #%d introuvable.', $slug, $id));

            return null;
        }

        if ($article->status !== ArticleStatus::PENDING) {
            $this->warn(sprintf(
                '  %s : article #%d au statut %s, seul un article en revue se publie.',
                $slug,
                $id,
                $article->status->value,
            ));

            return null;
        }

        return $article;
    }

    /**
     * The date to publish under: the first-version date of the entry.
     *
     * Falls back to nothing on purpose. An entry without that date is an
     * entry whose history nobody established, and publishing it at today
     * would silently put it at the top of the feed -- the one outcome
     * this whole command exists to avoid.
     *
     * @param  array<string, mixed>  $entry
     */
    private function resolveDate(string $slug, array $entry): ?Carbon
    {
        $raw = $entry['first_release_date'] ?? null;

        if (! is_string($raw) || $raw === '') {
            $this->warn(sprintf('  %s : aucune date de première version, ignoré.', $slug));

            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $raw);
        } catch (\Throwable $e) {
            $this->warn(sprintf('  %s : date illisible (%s), ignoré.', $slug, $raw));

            return null;
        }

        if ($date === null) {
            $this->warn(sprintf('  %s : date illisible (%s), ignoré.', $slug, $raw));

            return null;
        }

        return $date->startOfDay();
    }
}

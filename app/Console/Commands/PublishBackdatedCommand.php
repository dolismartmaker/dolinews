<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Review\ReviewException;
use App\Domain\Dolinews\Review\ReviewService;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Put articles in the feed at the date of the version they announce
 * (SPEC 5.1), whether they still await review or were already published
 * at the date the review accepted them.
 *
 * The companion of the publication scripts, which submit through the API
 * and cannot publish anything: a token grants the right to submit, never
 * to publish. This command runs on the instance, where the super admin's
 * derogation lives.
 *
 * It reads a manifest pairing each entry with the article it produced and
 * the date to carry. Two cases, in the same run:
 *
 *   - the article awaits review: it is published under that date;
 *   - the article is already published under another date: that date is
 *     corrected.
 *
 * The second case is what rescues the archives submitted before
 * back-dating existed. Both go through ReviewService, so the derogation
 * stays journalled with its motive and its date, the bootstrap ceiling
 * keeps counting the publications and only them, and the refusal on the
 * admin's own content outside the bootstrap phase still applies.
 *
 * Running it twice changes nothing the second time: an article already
 * carrying its date is reported as unchanged.
 *
 * Manifest entries identify their article by submitted_article_id, which
 * the catalogue script writes back as it submits. An archive whose id
 * nobody wrote down is identified by the project slug and the version
 * instead. Every article of the translation group follows: a translation
 * announces the same dated event as its source.
 */
class PublishBackdatedCommand extends Command
{
    protected $signature = 'dolinews:publish-backdated
        {manifest : Path to the manifest written by the publication scripts}
        {--admin= : Email of the publishing super admin, defaults to the only one}
        {--motive=Publication antidatée du catalogue : Motive recorded in the moderation journal}
        {--dry-run : Report what would be done, change nothing}';

    protected $description = 'Publish or redate articles under the date of the version they announce';

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
        $redated = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($entries as $slug => $entry) {
            // A manifest is written by hand as much as by a script, and
            // JSON has no comments: an underscored key carries the notes
            // the operator needs next to the dates.
            if (str_starts_with((string) $slug, '_')) {
                continue;
            }

            if (! is_array($entry)) {
                $this->warn(sprintf('  %s : entrée mal formée, ignorée.', $slug));
                $skipped++;

                continue;
            }

            $date = $this->resolveDate((string) $slug, $entry);

            if ($date === null) {
                $skipped++;

                continue;
            }

            $articles = $this->resolveArticles((string) $slug, $entry);

            if ($articles === []) {
                $skipped++;

                continue;
            }

            foreach ($articles as $article) {
                $outcome = $this->apply($review, $article, $admin, (string) $slug, $motive, $date, $dryRun);

                match ($outcome) {
                    'published' => $published++,
                    'redated' => $redated++,
                    'unchanged' => $unchanged++,
                    default => $skipped++,
                };
            }
        }

        $this->info(sprintf(
            '%d articles %s, %d %s, %d déjà à leur date, %d ignorés.',
            $published,
            $dryRun ? 'à publier' : 'publiés',
            $redated,
            $dryRun ? 'à redater' : 'redatés',
            $unchanged,
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * Publish or redate one article, and report which of the two it was.
     *
     * @return 'published'|'redated'|'unchanged'|'skipped'
     */
    private function apply(
        ReviewService $review,
        Article $article,
        User $admin,
        string $slug,
        string $motive,
        Carbon $date,
        bool $dryRun,
    ): string {
        $label = sprintf('%s [%s] article #%d', $slug, $article->locale, $article->getKey());

        if ($article->status === ArticleStatus::PENDING) {
            if ($dryRun) {
                $this->line(sprintf('  %s : publierait au %s', $label, $date->format('Y-m-d')));

                return 'published';
            }

            try {
                $review->publishByAdmin($article, $admin, $motive, $date);
            } catch (ReviewException $e) {
                // Never silent: a refused derogation is exactly what the
                // operator needs to read, and the run carries on with the
                // rest rather than abandoning what it could still do.
                $this->error(sprintf('  %s : refusé, %s', $label, $e->getMessage()));

                return 'skipped';
            }

            $this->line(sprintf('  %s : publié au %s', $label, $date->format('Y-m-d')));

            return 'published';
        }

        if ($article->status !== ArticleStatus::PUBLISHED) {
            $this->warn(sprintf(
                '  %s : au statut %s, seul un article en revue ou publié est traité.',
                $label,
                $article->status->value,
            ));

            return 'skipped';
        }

        if ($article->published_at !== null && $article->published_at->isSameDay($date)) {
            $this->line(sprintf('  %s : déjà au %s, inchangé.', $label, $date->format('Y-m-d')));

            return 'unchanged';
        }

        if ($dryRun) {
            $this->line(sprintf(
                '  %s : redaterait du %s au %s',
                $label,
                $article->published_at?->format('Y-m-d') ?? 'inconnue',
                $date->format('Y-m-d'),
            ));

            return 'redated';
        }

        try {
            $review->redatePublication($article, $admin, $motive, $date);
        } catch (ReviewException $e) {
            $this->error(sprintf('  %s : refusé, %s', $label, $e->getMessage()));

            return 'skipped';
        }

        $this->line(sprintf('  %s : redaté au %s', $label, $date->format('Y-m-d')));

        return 'redated';
    }

    /**
     * The manifest entries, or null when it cannot be read.
     *
     * @return array<string, mixed>|null
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
     * The articles a manifest entry designates: the one it points at and
     * the rest of its translation group, a translation announcing the
     * same dated event as its source.
     *
     * @param  array<string, mixed>  $entry
     * @return array<int, Article>
     */
    private function resolveArticles(string $slug, array $entry): array
    {
        $article = $this->resolveById($slug, $entry) ?? $this->resolveByVersion($slug, $entry);

        if ($article === null) {
            return [];
        }

        /** @var array<int, Article> $group */
        $group = $article->translations()->orderBy('id')->get()->all();

        return $group === [] ? [$article] : $group;
    }

    /**
     * The article whose id the submitting script wrote back.
     *
     * @param  array<string, mixed>  $entry
     */
    private function resolveById(string $slug, array $entry): ?Article
    {
        $id = $entry['submitted_article_id'] ?? null;

        if (! is_int($id)) {
            return null;
        }

        /** @var Article|null $article */
        $article = Article::query()->find($id);

        if ($article === null) {
            $this->warn(sprintf('  %s : article #%d introuvable.', $slug, $id));
        }

        return $article;
    }

    /**
     * The article an archive designates by project slug and version,
     * for the announcements submitted before any manifest recorded an
     * id. The source is looked up first, its group carrying the rest.
     *
     * @param  array<string, mixed>  $entry
     */
    private function resolveByVersion(string $slug, array $entry): ?Article
    {
        $projectSlug = $entry['project'] ?? null;
        $version = $entry['version'] ?? null;

        if (! is_string($projectSlug) || ! is_string($version) || $projectSlug === '' || $version === '') {
            $this->warn(sprintf(
                '  %s : ni submitted_article_id, ni couple project/version, ignoré.',
                $slug,
            ));

            return null;
        }

        /** @var Project|null $project */
        $project = Project::query()->where('slug', $projectSlug)->first();

        if ($project === null) {
            $this->warn(sprintf('  %s : fiche projet "%s" introuvable.', $slug, $projectSlug));

            return null;
        }

        $matches = Article::query()
            ->where('project_id', $project->getKey())
            ->where('version', $version)
            ->orderByDesc('is_source')
            ->orderBy('id')
            ->get();

        if ($matches->isEmpty()) {
            $this->warn(sprintf(
                '  %s : aucun article en version %s sur la fiche %s.',
                $slug,
                $version,
                $projectSlug,
            ));

            return null;
        }

        // Several submissions of the same version is an ambiguity the
        // command refuses to resolve by itself: redating the wrong one
        // is a correction nobody would think to check.
        $groups = $matches->pluck('translation_group_id')->unique();

        if ($groups->count() > 1) {
            $this->warn(sprintf(
                '  %s : %d annonces distinctes en version %s sur la fiche %s, '
                    .'précisez submitted_article_id.',
                $slug,
                $groups->count(),
                $version,
                $projectSlug,
            ));

            return null;
        }

        /** @var Article $first */
        $first = $matches->first();

        return $first;
    }

    /**
     * The date to publish under: the first-version date of the entry.
     *
     * Falls back to nothing on purpose. An entry without that date is an
     * entry whose history nobody established, and publishing it at today
     * would silently put it at the top of the feed -- the one outcome
     * this whole command exists to avoid. A repository without a tag has
     * no release date to read; the date is established by hand and
     * written into the manifest, never guessed from a first commit,
     * which dates the start of the work and not its publication.
     *
     * @param  array<string, mixed>  $entry
     */
    private function resolveDate(string $slug, array $entry): ?Carbon
    {
        $raw = $entry['first_release_date'] ?? null;

        if (! is_string($raw) || $raw === '') {
            $this->warn(sprintf(
                '  %s : aucune date de première version, ignoré. '
                    .'Renseignez first_release_date dans le manifeste.',
                $slug,
            ));

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

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Audit\AuditLogger;
use App\Domain\Dolinews\Articles\ArticleException;
use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * File announcements published without their project sheet.
 *
 * The rescue of a bulk deposit: the catalogue scripts submit an archive
 * in one run, and an entry whose sheet did not exist yet, or whose slug
 * was mistyped, lands in the feed attached to nothing. It reads
 * correctly, it is simply filed nowhere - absent from the sheet of the
 * module it announces, and from whatever a reader watching that module
 * receives.
 *
 * Nothing is repaired by guessing alone: with no option the command only
 * takes inventory. Filing happens under --map, which names the
 * destination of each announcement, or under --auto, which handles the
 * two cases where no choice is being made:
 *
 *   - the editor holds exactly one sheet, so the announcement has one
 *     possible destination and no other;
 *   - the source carries a sheet its own translations lack, which is not
 *     a decision but a group half filed.
 *
 * Whole translation groups move: project_id is borne by each article,
 * and a sheet listing a French version while its translations stay out
 * is the very state this command exists to end.
 *
 * Filing is not a correction (SPEC 5.4): no text changes, no review is
 * asked for anything, and no announcement carries a correction mention
 * afterwards. Each act is journalled with its motive.
 */
class LinkArticleProjectsCommand extends Command
{
    protected $signature = 'dolinews:link-articles
        {--map= : Path to a JSON file pairing an article id with a project slug}
        {--auto : File under the only sheet of the editor, and realign half-filed groups}
        {--motive=Rattachement de rattrapage : Motive recorded in the audit journal}
        {--dry-run : Report what would be done, change nothing}';

    protected $description = 'File announcements published without their project sheet';

    /**
     * Destinations read from --map, by article id.
     *
     * @var array<int, string>
     */
    private array $map = [];

    public function handle(ArticleService $articles, AuditLogger $audit): int
    {
        if (! $this->readMap()) {
            return self::FAILURE;
        }

        $auto = (bool) $this->option('auto');
        $dryRun = (bool) $this->option('dry-run');
        $motive = (string) $this->option('motive');

        $orphans = $this->orphanSources();
        $halfFiled = $auto ? $this->halfFiledGroups() : new Collection;

        if ($orphans->isEmpty() && $halfFiled->isEmpty()) {
            $this->info('Aucune annonce sans fiche.');

            return self::SUCCESS;
        }

        if ($this->map === [] && ! $auto) {
            $this->inventory($orphans, $articles);

            return self::SUCCESS;
        }

        $filed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($orphans->merge($halfFiled) as $article) {
            $project = $this->destinationOf($article, $auto);

            if ($project === null) {
                $this->line(sprintf(
                    '  %-6s %s : aucune destination, à nommer dans --map',
                    '#'.$article->getKey(),
                    $article->title,
                ));
                $skipped++;

                continue;
            }

            $size = $articles->translationGroup($article)->count();

            if ($dryRun) {
                $this->line(sprintf(
                    '  %-6s %s -> %s (%d article(s))',
                    '#'.$article->getKey(),
                    $article->title,
                    $project->slug,
                    $size,
                ));
                $filed++;

                continue;
            }

            try {
                $moved = $articles->linkProject($article, $project);
            } catch (ArticleException $e) {
                $this->error(sprintf('  #%d %s : %s', $article->getKey(), $article->title, $e->getMessage()));
                $failed++;

                continue;
            }

            // No authenticated user on a console run: the entry carries
            // the motive and says where it came from (SPEC 9.4).
            $audit->log('article.project_attached', $article, [
                'translation_group_id' => $article->translation_group_id,
                'project_id_after' => $project->getKey(),
                'articles' => $moved,
                'motive' => $motive,
                'source' => 'dolinews:link-articles',
            ]);

            $this->line(sprintf(
                '  %-6s %s -> %s (%d article(s))',
                '#'.$article->getKey(),
                $article->title,
                $project->slug,
                $moved,
            ));
            $filed++;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s : %d rangée(s), %d sans destination, %d refusée(s).',
            $dryRun ? 'Simulation' : 'Rattachement',
            $filed,
            $skipped,
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Announcements with no sheet at all, one line per translation group.
     *
     * @return Collection<int, Article>
     */
    private function orphanSources(): Collection
    {
        /** @var Collection<int, Article> $orphans */
        $orphans = Article::query()
            ->whereNull('project_id')
            ->where('is_source', true)
            ->with('editor')
            ->orderBy('id')
            ->get();

        return $orphans;
    }

    /**
     * Sources whose own translations lost the sheet, or kept one the
     * source no longer has: a group filed halfway, which --auto realigns
     * on the source without anyone choosing anything.
     *
     * @return Collection<int, Article>
     */
    private function halfFiledGroups(): Collection
    {
        /** @var Collection<int, Article> $sources */
        $sources = Article::query()
            ->whereNotNull('project_id')
            ->where('is_source', true)
            ->with('editor')
            ->orderBy('id')
            ->get();

        return $sources->filter(static function (Article $source): bool {
            return Article::query()
                ->where('translation_group_id', $source->translation_group_id)
                ->whereKeyNot($source->getKey())
                ->where(static fn ($query) => $query
                    ->whereNull('project_id')
                    ->orWhere('project_id', '!=', $source->project_id))
                ->exists();
        })->values();
    }

    /**
     * Where this announcement goes: the sheet named by --map, the only
     * sheet of its editor under --auto, or the sheet its own source
     * already carries. Null when nobody said and nothing is obvious.
     */
    private function destinationOf(Article $article, bool $auto): ?Project
    {
        $slug = $this->map[(int) $article->getKey()] ?? null;

        if ($slug !== null) {
            /** @var Project|null $named */
            $named = Project::query()->where('slug', $slug)->first();

            if ($named === null) {
                $this->warn(sprintf('  Fiche inconnue dans --map : %s', $slug));
            }

            return $named;
        }

        if (! $auto) {
            return null;
        }

        if ($article->project_id !== null) {
            /** @var Project|null $own */
            $own = Project::query()->find($article->project_id);

            return $own;
        }

        $sheets = Project::query()
            ->where('editor_id', $article->editor_id)
            ->get();

        return $sheets->count() === 1 ? $sheets->first() : null;
    }

    /**
     * What is unfiled, and what it could go under: the run that changes
     * nothing, and the one to start with.
     *
     * @param  Collection<int, Article>  $orphans
     */
    private function inventory(Collection $orphans, ArticleService $articles): void
    {
        $this->info(sprintf('%d annonce(s) sans fiche :', $orphans->count()));
        $this->newLine();

        $rows = [];

        foreach ($orphans as $article) {
            $sheets = Project::query()->where('editor_id', $article->editor_id)->count();

            $rows[] = [
                $article->getKey(),
                mb_substr($article->title, 0, 40),
                $article->version ?? '',
                $article->editor->slug,
                $articles->translationGroup($article)->count(),
                match (true) {
                    $sheets === 0 => 'éditeur sans fiche',
                    $sheets === 1 => 'fiche unique, --auto suffit',
                    default => $sheets.' fiches, à nommer dans --map',
                },
            ];
        }

        $this->table(['Id', 'Titre', 'Version', 'Éditeur', 'Langues', 'Destination'], $rows);
        $this->newLine();
        $this->line('Relancer avec --auto, ou avec --map=fichier.json pour nommer les destinations.');
        $this->line('Format du fichier : {"12": "slug-de-la-fiche", "18": "autre-fiche"}');
    }

    /**
     * Read --map, if given. False on an unusable file, which stops the
     * run rather than filing half an archive somewhere arbitrary.
     */
    private function readMap(): bool
    {
        $path = $this->option('map');

        if (! is_string($path) || $path === '') {
            return true;
        }

        if (! is_file($path)) {
            $this->error("Fichier de correspondance introuvable : {$path}");

            return false;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            $this->error("JSON invalide dans {$path}");

            return false;
        }

        foreach ($decoded as $id => $slug) {
            if (! is_numeric($id) || ! is_string($slug)) {
                $this->error('Chaque entrée associe un identifiant d\'article à un slug de fiche.');

                return false;
            }

            $this->map[(int) $id] = $slug;
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Translation\AutoProjectTranslationService;
use App\Domain\Dolinews\Translation\TranslationRouter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Machine-translate project sheets (SPEC 5.7, 4.2).
 *
 * A sheet of its own and not an option of dolinews:translate, where
 * --project already means "the announcements hanging under this sheet":
 * the same word would have designated two different sets of texts in
 * one command.
 *
 * Idempotent, like its announcement counterpart: it writes a version
 * that is missing, rewrites one the sheet has moved past, and does
 * nothing at all otherwise - so the daily sweep costs nothing on a
 * catalogue nobody edited, and an interrupted run is resumed by
 * repeating the command.
 *
 * What it does not lift, --force included: the editor's opt-in. What
 * goes out carries the editor's name, and a command run by the operator
 * is not the editor's consent (SPEC 5.7). --force lifts the monthly
 * allowance of the shared engine and nothing else, what is spent being
 * counted all the same.
 */
class TranslateSheetsCommand extends Command
{
    protected $signature = 'dolinews:translate-sheets
        {--project= : Slug or id of one sheet}
        {--editor= : Slug or id of an editor, every sheet it publishes}
        {--limit= : Most sheets to process}
        {--force : Spend past the monthly allowance of the shared engine, still counted}
        {--dry-run : Report what would be produced, write nothing}';

    protected $description = 'Machine-translate project sheets, by sheet or by editor';

    public function __construct(
        private readonly AutoProjectTranslationService $translations,
        private readonly TranslationRouter $router,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sheets = $this->resolveSheets();

        if ($sheets === null) {
            return self::FAILURE;
        }

        if ($sheets === []) {
            $this->info('Aucune fiche à traiter.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $written = 0;
        $skipped = 0;

        foreach ($sheets as $sheet) {
            $editor = $sheet->editor;

            if ($editor === null) {
                continue;
            }

            $route = $this->router->resolve($editor, ignoreCeiling: $force);

            if ($route['engine'] === null) {
                $skipped++;
                $this->line(sprintf(
                    '  %s : ignorée (%s)',
                    $sheet->slug,
                    (string) $route['reason'],
                ));

                continue;
            }

            $missing = $this->translations->missingLocales($sheet, $route['engine']);
            $outdated = $this->translations->outdatedTranslations($sheet)
                ->pluck('locale')
                ->all();

            if ($missing === [] && $outdated === []) {
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '  %s : %d à écrire, %d à reprendre (%s)',
                    $sheet->slug,
                    count($missing),
                    count($outdated),
                    implode(', ', array_merge($missing, $outdated)),
                ));

                continue;
            }

            $count = $this->translations->sync($sheet, $force);
            $written += $count;

            if ($count > 0) {
                $this->line(sprintf('  %s : %d version(s)', $sheet->slug, $count));
            }
        }

        $this->info($dryRun
            ? sprintf('%d fiche(s) examinée(s), rien écrit.', count($sheets))
            : sprintf('%d version(s) écrite(s) sur %d fiche(s).', $written, count($sheets)));

        if ($skipped > 0) {
            $this->line(sprintf('%d fiche(s) sans moteur disponible.', $skipped));
        }

        return self::SUCCESS;
    }

    /**
     * The sheets the options designate, null when an option names
     * something that does not exist.
     *
     * @return array<int, Project>|null
     */
    private function resolveSheets(): ?array
    {
        $query = Project::query()->with('editor', 'translations');

        $project = (string) ($this->option('project') ?? '');

        if ($project !== '') {
            $query->where(function (Builder $inner) use ($project): void {
                $inner->where('slug', $project);

                if (ctype_digit($project)) {
                    $inner->orWhere('id', (int) $project);
                }
            });
        }

        $editorOption = (string) ($this->option('editor') ?? '');

        if ($editorOption !== '') {
            /** @var Editor|null $editor */
            $editor = Editor::query()
                ->where('slug', $editorOption)
                ->when(ctype_digit($editorOption), fn (Builder $inner): Builder => $inner->orWhere('id', (int) $editorOption))
                ->first();

            if ($editor === null) {
                $this->error(sprintf('Éditeur inconnu : %s', $editorOption));

                return null;
            }

            $query->where('editor_id', $editor->getKey());
        }

        $limit = (int) ($this->option('limit') ?? 0);

        if ($limit > 0) {
            $query->limit($limit);
        }

        /** @var array<int, Project> $sheets */
        $sheets = $query->orderBy('id')->get()->all();

        if ($project !== '' && $sheets === []) {
            $this->error(sprintf('Fiche inconnue : %s', $project));

            return null;
        }

        return $sheets;
    }
}

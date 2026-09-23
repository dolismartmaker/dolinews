<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Translation\AutoTranslationException;
use App\Domain\Dolinews\Translation\AutoTranslationService;
use App\Domain\Dolinews\Translation\TranslationRouter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Machine-translate published announcements on demand (SPEC 5.7).
 *
 * The companion of the queued job, which only ever fires on a
 * publication or a revision: this command is what catches up on a
 * catalogue deposited before an editor enabled translation, on a
 * language opened after the fact, or on a run the monthly ceiling cut
 * short. Three scopes, from the narrowest up - one announcement, a
 * project sheet, an editor - because that is the order in which an
 * operator actually needs it.
 *
 * Two bounds it does not lift, and must not:
 *
 * - the editor's opt-in (editors.auto_translate) is required, with no
 *   option to bypass it, --force included. SPEC 5.7 lets the editor's
 *   own click on its own screen stand for consent; a command run by the
 *   operator is not that click, and what comes out goes under the
 *   editor's name;
 * - --locale is NOT bounded by editors.translation_locales, on the same
 *   reasoning SPEC 5.7 applies to the single-announcement button: that
 *   selection says what happens by itself, not what may be asked for.
 *
 * --force lifts exactly one thing, the monthly allowance of the shared
 * engine. The ceiling shares a resource of the operator's own between
 * editors (SPEC 5.7), so the operator may decide to spend past it -
 * typically to finish a catalogue a run stopped halfway through. Three
 * things keep that from turning the ceiling into a formality: what is
 * spent is still counted, so the next month starts from the truth; the
 * run says by how much it went over; and no screen and no API point
 * reaches the option, so an editor can neither ask for it nor be sold
 * it.
 *
 * Everything else is the service's business and stays untouched: the
 * editor's key before the shared engine, the date inherited from the
 * source, publication without review, and the human translations nobody
 * overwrites. Safe to re-run: it writes nothing when there is nothing
 * to write, so an interrupted catalogue is resumed by repeating the
 * same command.
 *
 * --replay is a mode of its own, not a variant of the above: it hands
 * the endpoint again what it has already been handed and discards the
 * answer, for a deployment rebuilding its corpus. Same targets, same
 * languages, but it translates nothing - see
 * AutoTranslationService::replay() for what it lifts and what it keeps.
 */
class TranslateAnnouncementsCommand extends Command
{
    protected $signature = 'dolinews:translate
        {--article= : Id or slug of one announcement}
        {--project= : Slug or id of a project, every published announcement on it}
        {--editor= : Slug or id of an editor, every published announcement of its own}
        {--locale=* : Target content locales, repeatable; default, the ones the editor asked for}
        {--limit= : Most announcements to process}
        {--replay : Send the shared engine again what it already translated, apply nothing}
        {--force : Spend past the monthly allowance of the shared engine, still counted}
        {--dry-run : Report what would be produced, write nothing}';

    protected $description = 'Machine-translate published announcements, by article, project or editor';

    public function __construct(
        private readonly AutoTranslationService $translations,
        private readonly TranslationRouter $router,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sources = $this->resolveSources();

        if ($sources === null) {
            return self::FAILURE;
        }

        $locales = $this->resolveLocales();

        if ($locales === null) {
            return self::FAILURE;
        }

        if ($sources === []) {
            $this->warn('Aucune annonce publiée sur cette cible.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if ((bool) $this->option('replay')) {
            // The allowance does not apply to a replay in the first
            // place: nothing is published, so nothing is counted.
            if ($force) {
                $this->warn('--force ne s\'applique pas à --replay : un renvoi ne tire pas sur le volume mensuel.');
            }

            return $this->replay($sources, $locales, $dryRun);
        }

        if ($force) {
            $this->overspendNotice($sources);
        }

        $written = 0;
        $refused = 0;
        $idle = 0;

        foreach ($sources as $source) {
            $label = $this->label($source);

            // One decision point for the engine, the key and the ceiling.
            // Resolved WITHOUT the on-demand flag, which is precisely what
            // keeps the editor's opt-in required here. --force lifts the
            // ceiling and only the ceiling.
            $route = $this->router->resolve($source->editor, ignoreCeiling: $force);
            $engine = $route['engine'];

            if ($engine === null) {
                $this->warn(sprintf('  %s : ignoré, %s', $label, $this->reasonText($route['reason'])));
                $refused++;

                continue;
            }

            $targets = $locales === []
                ? $this->translations->missingLocales($source, $engine)
                : $this->requestedTargets($source, $locales);

            $outdated = $locales === []
                ? $this->translations->outdatedTranslations($source)->count()
                : 0;

            if ($dryRun) {
                $this->reportDryRun($label, $targets, $outdated);
                $written += count($targets) + $outdated;

                if ($targets === [] && $outdated === 0) {
                    $idle++;
                }

                continue;
            }

            if ($targets === [] && $outdated === 0) {
                $this->line(sprintf('  %s : rien à écrire.', $label));
                $idle++;

                continue;
            }

            if ($locales === []) {
                $count = $this->translations->sync($source, $force);
                $written += $count;

                $this->line($count > 0
                    ? sprintf('  %s : %d version(s) écrite(s).', $label, $count)
                    : sprintf('  %s : le moteur n\'a rien renvoyé, rien n\'a été publié.', $label));

                continue;
            }

            foreach ($targets as $locale) {
                try {
                    $this->translations->translateInto($source, $locale, $force);
                } catch (AutoTranslationException $e) {
                    // Never silent, and never fatal: the refusal of one
                    // language is what the operator needs to read, and the
                    // rest of the catalogue is still worth doing.
                    $this->error(sprintf('  %s [%s] : %s', $label, $locale, $e->getMessage()));
                    $refused++;

                    continue;
                }

                $this->line(sprintf('  %s : version %s publiée.', $label, $locale));
                $written++;
            }
        }

        $this->info(sprintf(
            '%d annonce(s) parcourue(s), %d version(s) %s, %d refus, %d sans rien à écrire.',
            count($sources),
            $written,
            $dryRun ? 'à écrire' : 'écrite(s)',
            $refused,
            $idle,
        ));

        if ($force && ! $dryRun) {
            $this->overspendSummary($sources);
        }

        return self::SUCCESS;
    }

    /**
     * Hand the shared endpoint again what it has already translated, and
     * apply none of what it answers.
     *
     * The languages are those the announcement already has a machine
     * version in, which is exactly what was sent the first time. Naming
     * --locale widens that to languages never sent, which is a different
     * act and has to be asked for.
     *
     * @param  array<int, Article>  $sources
     * @param  array<int, string>  $locales
     */
    private function replay(array $sources, array $locales, bool $dryRun): int
    {
        if (! $this->router->sharedEngine()->isAvailable()) {
            $this->error('Aucun point d\'accès de traduction n\'est configuré : rien à renvoyer.');

            return self::FAILURE;
        }

        $sent = 0;
        $characters = 0;
        $refused = 0;
        $idle = 0;

        /** @var array<int, int> $ownKeyNoted */
        $ownKeyNoted = [];

        foreach ($sources as $source) {
            $label = $this->label($source);
            $editor = $source->editor;

            // The opt-in is the one bound a replay keeps: nothing is
            // published, but the text leaves for a third party all the
            // same, and that is what the editor agreed to.
            if (! $editor->auto_translate) {
                $this->warn(sprintf('  %s : ignoré, %s', $label, $this->reasonText(TranslationRouter::REASON_DISABLED)));
                $refused++;

                continue;
            }

            // Said once per editor: an editor on its own key had its
            // versions produced elsewhere, so this is not a replay for
            // the shared endpoint, it is text it never saw.
            if (trim((string) ($editor->translation_api_key ?? '')) !== ''
                && ! in_array($editor->getKey(), $ownKeyNoted, true)) {
                $this->warn(sprintf(
                    '  %s traduit sur sa propre clé : ces textes ne sont jamais passés par le moteur partagé.',
                    $editor->slug,
                ));
                $ownKeyNoted[] = $editor->getKey();
            }

            $targets = $locales === []
                ? $this->translations->replayableLocales($source)
                : array_values(array_filter(
                    $locales,
                    static fn (string $locale): bool => $locale !== $source->locale,
                ));

            if ($targets === []) {
                $this->line(sprintf('  %s : rien à renvoyer.', $label));
                $idle++;

                continue;
            }

            if ($dryRun) {
                $volume = $this->translations->outgoingCharacters($source) * count($targets);

                $this->line(sprintf(
                    '  %s : renverrait %s, %d caractères.',
                    $label,
                    implode(', ', $targets),
                    $volume,
                ));

                $sent += count($targets);
                $characters += $volume;

                continue;
            }

            foreach ($targets as $locale) {
                try {
                    $volume = $this->translations->replay($source, $locale);
                } catch (AutoTranslationException $e) {
                    $this->error(sprintf('  %s [%s] : %s', $label, $locale, $e->getMessage()));
                    $refused++;

                    continue;
                }

                if ($volume === null) {
                    $this->error(sprintf('  %s [%s] : le moteur n\'a rien renvoyé.', $label, $locale));
                    $refused++;

                    continue;
                }

                $this->line(sprintf('  %s : lot %s renvoyé, %d caractères.', $label, $locale, $volume));
                $sent++;
                $characters += $volume;
            }
        }

        $this->info(sprintf(
            '%d annonce(s) parcourue(s), %d lot(s) %s, %d caractères, %d refus, %d sans rien à renvoyer.',
            count($sources),
            $sent,
            $dryRun ? 'à renvoyer' : 'renvoyé(s)',
            $characters,
            $refused,
            $idle,
        ));

        return self::SUCCESS;
    }

    /**
     * The announcements the target designates: published sources only,
     * newest first like every other listing of the feed.
     *
     * A translation is never a target, it is a result: pointing at one
     * translates its group's source instead, which is what the caller
     * meant.
     *
     * @return array<int, Article>|null
     */
    private function resolveSources(): ?array
    {
        $targets = array_filter([
            'article' => trim((string) $this->option('article')),
            'project' => trim((string) $this->option('project')),
            'editor' => trim((string) $this->option('editor')),
        ], static fn (string $value): bool => $value !== '');

        if (count($targets) !== 1) {
            $this->error('Une cible et une seule : --article, --project ou --editor.');

            return null;
        }

        $sources = match (array_key_first($targets)) {
            'article' => $this->resolveArticle(reset($targets)),
            'project' => $this->resolveProject(reset($targets)),
            default => $this->resolveEditor(reset($targets)),
        };

        if ($sources === null) {
            return null;
        }

        $raw = trim((string) $this->option('limit'));

        if ($raw === '') {
            return $sources;
        }

        // Never read as zero in silence: a mistyped limit would otherwise
        // translate the whole catalogue of an editor.
        if (! ctype_digit($raw) || (int) $raw < 1) {
            $this->error('--limit attend un entier positif : '.$raw);

            return null;
        }

        return array_slice($sources, 0, (int) $raw);
    }

    /**
     * One announcement, by id or by slug.
     *
     * @return array<int, Article>|null
     */
    private function resolveArticle(string $reference): ?array
    {
        $matches = ctype_digit($reference)
            ? Article::query()->whereKey((int) $reference)->get()
            : Article::query()->where('slug', $reference)->orderBy('id')->get();

        if ($matches->isEmpty()) {
            $this->error('Aucune annonce ne porte cette référence : '.$reference);

            return null;
        }

        // A slug is unique per project sheet, not across the feed: two
        // matches is an ambiguity the command refuses to resolve by
        // itself, translating the wrong announcement being a publication
        // nobody asked for.
        if ($matches->count() > 1) {
            $this->error(sprintf(
                '%d annonces portent le slug "%s" : désignez-la par son identifiant.',
                $matches->count(),
                $reference,
            ));

            return null;
        }

        /** @var Article $article */
        $article = $matches->first();
        $source = $article->isTranslation() ? $article->sourceArticle() : $article;

        if ($source === null) {
            $this->error(sprintf(
                'L\'annonce #%d est une traduction dont la version d\'origine est introuvable.',
                $article->getKey(),
            ));

            return null;
        }

        if ($source->getKey() !== $article->getKey()) {
            $this->line(sprintf(
                '  Annonce #%d traduite : traitement de sa version d\'origine #%d.',
                $article->getKey(),
                $source->getKey(),
            ));
        }

        if ($source->status !== ArticleStatus::PUBLISHED || $source->deleted_at !== null) {
            $this->error(sprintf(
                'L\'annonce #%d n\'est pas publiée : seule une annonce parue se traduit.',
                $source->getKey(),
            ));

            return null;
        }

        return [$source];
    }

    /**
     * Every published announcement on a project sheet.
     *
     * @return array<int, Article>|null
     */
    private function resolveProject(string $reference): ?array
    {
        /** @var Project|null $project */
        $project = ctype_digit($reference)
            ? Project::query()->whereKey((int) $reference)->first()
            : Project::query()->where('slug', $reference)->first();

        if ($project === null) {
            $this->error('Aucune fiche projet ne porte cette référence : '.$reference);

            return null;
        }

        return $this->publishedSources()
            ->where('project_id', $project->getKey())
            ->get()
            ->all();
    }

    /**
     * Every published announcement of an editor, its announcements
     * without a sheet included.
     *
     * Scoped on editor_id and not on its project sheets: an announcement
     * with no project belongs to its editor all the same (SPEC 5.3), and
     * going through the sheets would leave those out.
     *
     * @return array<int, Article>|null
     */
    private function resolveEditor(string $reference): ?array
    {
        /** @var Editor|null $editor */
        $editor = ctype_digit($reference)
            ? Editor::query()->whereKey((int) $reference)->first()
            : Editor::query()->where('slug', $reference)->first();

        if ($editor === null) {
            $this->error('Aucun éditeur ne porte cette référence : '.$reference);

            return null;
        }

        return $this->publishedSources()
            ->where('editor_id', $editor->getKey())
            ->get()
            ->all();
    }

    /**
     * Published sources, newest first.
     *
     * @return Builder<Article>
     */
    private function publishedSources(): Builder
    {
        return Article::query()
            ->published()
            ->where('is_source', true)
            ->orderByDesc('published_at');
    }

    /**
     * The content locales asked for, or an empty list meaning "whatever
     * the editor asked for", which is what the service decides on its own.
     *
     * @return array<int, string>|null
     */
    private function resolveLocales(): ?array
    {
        /** @var array<int, string> $asked */
        $asked = array_values(array_unique(array_map(
            static fn (mixed $locale): string => trim((string) $locale),
            (array) $this->option('locale'),
        )));

        /** @var array<int, string> $content */
        $content = (array) config('dolinews.content_locales', []);

        foreach ($asked as $locale) {
            if (! in_array($locale, $content, true)) {
                $this->error(sprintf(
                    'Langue inconnue du service : %s. Attendu parmi %s.',
                    $locale,
                    implode(', ', $content),
                ));

                return null;
            }
        }

        return $asked;
    }

    /**
     * Among the languages asked for, those this announcement has no
     * version in.
     *
     * Filtered here rather than left to the service: a language already
     * present is nothing to report on a catalogue of a hundred
     * announcements, where it would drown the refusals that matter.
     *
     * @param  array<int, string>  $asked
     * @return array<int, string>
     */
    private function requestedTargets(Article $source, array $asked): array
    {
        /** @var array<int, string> $existing */
        $existing = $source->translations()->pluck('locale')->all();

        return array_values(array_filter(
            $asked,
            static fn (string $locale): bool => $locale !== $source->locale
                && ! in_array($locale, $existing, true),
        ));
    }

    /**
     * What the run would do to one announcement.
     *
     * @param  array<int, string>  $targets
     */
    private function reportDryRun(string $label, array $targets, int $outdated): void
    {
        if ($targets === [] && $outdated === 0) {
            $this->line(sprintf('  %s : rien à écrire.', $label));

            return;
        }

        $parts = [];

        if ($targets !== []) {
            $parts[] = 'produirait '.implode(', ', $targets);
        }

        if ($outdated > 0) {
            $parts[] = sprintf('rafraîchirait %d version(s) périmée(s)', $outdated);
        }

        $this->line(sprintf('  %s : %s.', $label, implode(', ', $parts)));
    }

    /**
     * Why no engine serves this editor, said plainly: a spent ceiling, a
     * missing opt-in and an unconfigured deployment call for three
     * different things.
     */
    /**
     * Say, before spending anything, which editors of the batch are
     * already at their monthly allowance and are about to go past it.
     *
     * Named editor by editor rather than as one line: --editor is one
     * of three scopes, and a run on a project or on a single
     * announcement still has to say whose allowance it is spending.
     *
     * Logged as well as printed. A terminal scrolls away, and going
     * over a shared allowance is the kind of act that gets questioned
     * a month later, when the only thing left is the log.
     *
     * @param  array<int, Article>  $sources
     */
    private function overspendNotice(array $sources): void
    {
        $ceiling = $this->router->ceiling();

        foreach ($this->editorsOf($sources) as $editor) {
            // An editor on its own key draws on nothing of ours, so
            // there is no allowance to go past.
            if (trim((string) ($editor->translation_api_key ?? '')) !== '') {
                continue;
            }

            $spent = $this->router->spent($editor);

            if ($spent < $ceiling) {
                continue;
            }

            $this->warn(sprintf(
                '  %s : volume mensuel atteint (%d / %d caractères), --force passe outre. Ce qui suit est compté.',
                $editor->slug,
                $spent,
                $ceiling,
            ));

            Log::notice('dolinews:translate --force: spending past the monthly allowance', [
                'editor_id' => $editor->getKey(),
                'editor_slug' => $editor->slug,
                'period' => $this->router->period(),
                'spent' => $spent,
                'ceiling' => $ceiling,
            ]);
        }
    }

    /**
     * What the run ended up spending past the allowance, per editor.
     *
     * @param  array<int, Article>  $sources
     */
    private function overspendSummary(array $sources): void
    {
        $ceiling = $this->router->ceiling();

        foreach ($this->editorsOf($sources) as $editor) {
            if (trim((string) ($editor->translation_api_key ?? '')) !== '') {
                continue;
            }

            $over = $this->router->spent($editor) - $ceiling;

            if ($over <= 0) {
                continue;
            }

            $this->warn(sprintf(
                '  %s : %d caractère(s) au-delà du volume du mois, reconduit le %s.',
                $editor->slug,
                $over,
                now()->addMonthNoOverflow()->startOfMonth()->format('d/m/Y'),
            ));
        }
    }

    /**
     * The distinct editors of a batch, in the order they appear.
     *
     * @param  array<int, Article>  $sources
     * @return array<int, Editor>
     */
    private function editorsOf(array $sources): array
    {
        $editors = [];

        foreach ($sources as $source) {
            $editors[$source->editor->getKey()] = $source->editor;
        }

        return array_values($editors);
    }

    private function reasonText(?string $reason): string
    {
        return match ($reason) {
            TranslationRouter::REASON_DISABLED => 'la traduction automatique n\'est pas activée par cet éditeur.',
            TranslationRouter::REASON_QUOTA_SPENT => 'le volume de traduction du mois est atteint pour cet éditeur.',
            TranslationRouter::REASON_NO_ENGINE => 'aucun point d\'accès de traduction n\'est configuré.',
            default => 'aucun moteur de traduction disponible.',
        };
    }

    /**
     * How one announcement is named in the report.
     */
    private function label(Article $source): string
    {
        return sprintf(
            '%s/%s [%s] #%d',
            $source->editor->slug,
            $source->slug,
            $source->locale,
            $source->getKey(),
        );
    }
}

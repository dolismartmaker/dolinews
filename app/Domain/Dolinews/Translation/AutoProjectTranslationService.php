<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Translation;

use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\ProjectTranslation;
use App\Domain\Dolinews\Projects\ProjectService;
use App\Domain\Dolinews\Projects\SheetRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Automatic translation of project sheets (SPEC 5.7, 4.2).
 *
 * The announcements were translated and the sheets they hang under
 * were not, so a Spanish reader landing on a module read its history in
 * Spanish and the project itself in French. A sheet is the permanent
 * half of what the service holds (D1): leaving it in one language is
 * the diffusion penalty SPEC 6.1 refuses, one page lower.
 *
 * Same bounds as the announcements, for the same reasons:
 *
 * - it follows the editor's opt-in (editors.auto_translate) and its
 *   language selection. What goes out carries the editor's name;
 * - it never touches a human translation. A regeneration only rewrites
 *   what the engine itself wrote (project_translations.auto_translated);
 * - fenced code is held out of the translation (MarkdownSegments): a
 *   sheet shows configuration lines no engine must rewrite;
 * - all or nothing on the fields it writes.
 *
 * Two differences with an announcement, and both are deliberate.
 *
 * The NAME IS NOT TRANSLATED. A module is called Facturx or capMail in
 * every language: it is a name, and engines turn names into phrases.
 * The reference name is copied as it stands, and an editor who really
 * wants another name in Greek writes that translation by hand, where
 * this service will not overwrite it.
 *
 * And staleness is a fingerprint rather than a revision number: a sheet
 * has no review circuit and no revision counter, it is edited in place.
 * The fingerprint of the text a version was written against is the only
 * thing that can say, later, that the sheet has moved on.
 */
class AutoProjectTranslationService
{
    public function __construct(
        private readonly TranslationRouter $router,
        private readonly ProjectService $projects,
    ) {}

    /**
     * Bring the language versions of a sheet up to date: write the
     * missing ones, then rewrite the machine ones their source has
     * moved past.
     *
     * Safe to run twice: it writes nothing when there is nothing to do.
     *
     * $force spends past the monthly allowance and nothing else, for
     * the operator's command line (SPEC 5.7). The editor's opt-in is
     * not among what it lifts.
     */
    public function sync(Project $project, bool $force = false): int
    {
        $editor = $project->editor;

        if ($editor === null) {
            return 0;
        }

        $route = $this->router->resolve($editor, ignoreCeiling: $force);
        $engine = $route['engine'];

        if ($engine === null) {
            Log::info('AutoProjectTranslationService: no engine for this sheet', [
                'project_id' => $project->getKey(),
                'reason' => $route['reason'],
            ]);

            return 0;
        }

        $shared = $route['route'] === TranslationRouter::ROUTE_SHARED;
        $written = 0;

        foreach ($this->missingLocales($project, $engine) as $locale) {
            if ($this->write($project, $locale, $engine, $shared) !== null) {
                $written++;
            }
        }

        foreach ($this->outdatedTranslations($project) as $stale) {
            if ($this->write($project, $stale->locale, $engine, $shared) !== null) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * One language version, on demand (SPEC 5.7): one sheet, one
     * language, one button.
     *
     * The call itself stands for the editor's consent, as it does for
     * an announcement: clicking "translate into Spanish" on a sheet one
     * is reading says more than a checkbox ticked once.
     */
    public function translateInto(Project $project, string $locale, bool $force = false): ?ProjectTranslation
    {
        $editor = $project->editor;

        if ($editor === null || $locale === $project->locale) {
            return null;
        }

        $existing = $project->translations()->where('locale', $locale)->first();

        // A human wrote this version: it is not the engine's to rewrite,
        // on demand no more than in a sweep.
        if ($existing !== null && ! $existing->auto_translated) {
            Log::info('AutoProjectTranslationService: human translation left alone', [
                'project_id' => $project->getKey(),
                'locale' => $locale,
            ]);

            return null;
        }

        $route = $this->router->resolve($editor, onDemand: true, ignoreCeiling: $force);
        $engine = $route['engine'];

        if ($engine === null) {
            return null;
        }

        return $this->write(
            $project,
            $locale,
            $engine,
            $route['route'] === TranslationRouter::ROUTE_SHARED,
        );
    }

    /**
     * Whether automatic translation applies to this sheet right now,
     * engine and ceiling included.
     */
    public function appliesTo(Project $project): bool
    {
        $editor = $project->editor;

        return $editor !== null && $this->router->resolve($editor)['engine'] !== null;
    }

    /**
     * The locales this sheet has no version in yet, among those the
     * service publishes in, the engine handles, and the editor asked
     * for.
     *
     * @return array<int, string>
     */
    public function missingLocales(Project $project, ?TranslationEngine $engine = null): array
    {
        $editor = $project->editor;

        if ($editor === null) {
            return [];
        }

        $engine ??= $this->router->resolve($editor)['engine'];

        /** @var array<int, string> $content */
        $content = (array) config('dolinews.content_locales', []);
        $supported = $engine?->supportedLocales() ?? [];
        $wanted = $editor->translation_locales ?? [];
        $existing = $project->translations()->pluck('locale')->all();

        return array_values(array_filter(
            $content,
            static fn (string $locale): bool => $locale !== $project->locale
                && ! in_array($locale, $existing, true)
                && ($supported === [] || in_array($locale, $supported, true))
                && ($wanted === [] || in_array($locale, $wanted, true)),
        ));
    }

    /**
     * The machine versions written against a sheet that has since
     * changed, those a refresh would rewrite.
     *
     * The editor's language selection is deliberately absent: it says
     * which versions to produce, not which ones to keep correct. A
     * language dropped from the selection stops gaining versions; the
     * ones already published go on being fixed (SPEC 5.7).
     *
     * @return Collection<int, ProjectTranslation>
     */
    public function outdatedTranslations(Project $project): Collection
    {
        $fingerprint = self::fingerprint($project);

        return $project->translations()
            ->where('auto_translated', true)
            ->where(function ($query) use ($fingerprint): void {
                $query->whereNull('source_fingerprint')
                    ->orWhere('source_fingerprint', '!=', $fingerprint);
            })
            ->get();
    }

    /**
     * The state of the source text a version was written against.
     *
     * The name is in it although it is never translated: renaming the
     * sheet changes what every version should copy.
     */
    public static function fingerprint(Project $project): string
    {
        return hash('sha256', implode("\0", [
            $project->locale,
            $project->name,
            $project->summary,
            (string) $project->description,
        ]));
    }

    /**
     * Translate and write one version, or nothing at all.
     */
    private function write(
        Project $project,
        string $locale,
        TranslationEngine $engine,
        bool $shared,
    ): ?ProjectTranslation {
        $editor = $project->editor;

        if ($editor === null) {
            return null;
        }

        $segments = MarkdownSegments::split((string) $project->description);
        $batch = array_merge([$project->summary], $segments->translatableTexts());
        $characters = (int) array_sum(array_map('mb_strlen', $batch));

        $translated = $engine->translateBatch($batch, $project->locale, $locale);

        if ($translated === null || count($translated) !== count($batch)) {
            Log::warning('AutoProjectTranslationService: batch unanswered, version skipped', [
                'project_id' => $project->getKey(),
                'locale' => $locale,
            ]);

            return null;
        }

        // Booked after the answer: a call that produced no text cost the
        // service nothing, and must not spend an editor's ceiling.
        if ($shared) {
            $this->router->record($editor, $characters);
        }

        $description = $segments->reassemble(array_slice($translated, 1));

        // The bound is on what is WRITTEN, not on what a translation
        // makes of it: German runs longer, and dropping a version for a
        // tenth over the limit would lose the language for nothing. Twice
        // the bound is no longer length, it is an engine answering with
        // something else.
        if (mb_strlen($description) > SheetRules::descriptionMax() * 2) {
            Log::warning('AutoProjectTranslationService: translated description out of proportion, version skipped', [
                'project_id' => $project->getKey(),
                'locale' => $locale,
                'length' => mb_strlen($description),
            ]);

            return null;
        }

        return $this->projects->translate($project, $locale, [
            // Never translated: a module is called Facturx in every
            // language, and an engine turns a name into a phrase.
            'name' => $project->name,
            'summary' => SummaryFit::fit($translated[0], SheetRules::SUMMARY_MAX),
            'description' => trim($description) === '' ? null : $description,
            'auto_translated' => true,
            'source_fingerprint' => self::fingerprint($project),
        ]);
    }
}

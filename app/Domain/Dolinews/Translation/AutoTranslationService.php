<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Translation;

use App\Domain\Dolinews\Articles\RevisionService;
use App\Domain\Dolinews\Articles\TranslationPublisher;
use App\Domain\Dolinews\Articles\TranslationService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Automatic translation of announcements (SPEC 5.7).
 *
 * Without it, D14 stays decorative: a French editor will not write its
 * announcements in Polish and in Greek, so the language filter finds
 * French and English and the nine communities the service addresses read
 * a service that does not speak to them.
 *
 * What the service produces goes out under the editor's name, hence the
 * bounds:
 *
 * - it is an OPT-IN of the editor (editors.auto_translate), never on by
 *   default. The service would otherwise make an editor say, in a
 *   language it does not read, things it never wrote - and a contresens
 *   on a security announcement engages its name and, through SPEC 9.7,
 *   the operator's editorial responsibility;
 * - it never touches a human translation. A regeneration only rewrites
 *   what the engine itself produced (articles.auto_translated);
 * - a machine version is published like any version of its editor: at
 *   the date of its source, without review (SPEC 5.1).
 *
 * It is free, and has to be: SPEC 12 forbids charging for visibility,
 * and a translation makes an announcement appear in a language filter
 * where it was absent.
 */
class AutoTranslationService
{
    public function __construct(
        private readonly TranslationEngine $engine,
        private readonly TranslationService $translations,
        private readonly TranslationPublisher $publisher,
        private readonly RevisionService $revisions,
    ) {}

    /**
     * Bring the language versions of an announcement up to date: fill in
     * the missing locales, then refresh the machine versions their
     * source has moved past.
     *
     * Safe to run twice: it writes nothing when there is nothing to do.
     */
    public function sync(Article $source): int
    {
        if (! $this->appliesTo($source)) {
            return 0;
        }

        return $this->fillMissingLocales($source) + $this->refreshOutdated($source);
    }

    /**
     * Whether automatic translation applies to this announcement.
     */
    public function appliesTo(Article $source): bool
    {
        if ($source->isTranslation() || $source->status !== ArticleStatus::PUBLISHED) {
            return false;
        }

        if (! $source->editor->auto_translate) {
            return false;
        }

        return $this->engine->isAvailable();
    }

    /**
     * The locales this announcement has no version in yet, among those
     * the service publishes in and the engine handles.
     *
     * @return array<int, string>
     */
    public function missingLocales(Article $source): array
    {
        /** @var array<int, string> $content */
        $content = (array) config('dolinews.content_locales', []);
        $supported = $this->engine->supportedLocales();

        $existing = $source->translations()->pluck('locale')->all();

        return array_values(array_filter(
            $content,
            static fn (string $locale): bool => $locale !== $source->locale
                && ! in_array($locale, $existing, true)
                && ($supported === [] || in_array($locale, $supported, true)),
        ));
    }

    /**
     * Produce and publish the versions the announcement lacks.
     */
    private function fillMissingLocales(Article $source): int
    {
        $author = $source->author_user_id !== null
            ? User::query()->find($source->author_user_id)
            : null;

        if ($author === null) {
            Log::warning('AutoTranslationService: announcement without author, skipped', [
                'article_id' => $source->getKey(),
            ]);

            return 0;
        }

        $done = 0;

        foreach ($this->missingLocales($source) as $locale) {
            $fields = $this->translateFields($source, $locale);

            if ($fields === null) {
                continue;
            }

            $translation = $this->translations->submitTranslation($source, $author, $locale, $fields);
            $translation->auto_translated = true;
            $translation->save();

            $this->publisher->publish($translation, $source);
            $done++;
        }

        if ($done > 0) {
            Log::info('AutoTranslationService: versions produced', [
                'article_id' => $source->getKey(),
                'count' => $done,
            ]);
        }

        return $done;
    }

    /**
     * Rewrite the machine versions written against an older source.
     *
     * Through the revision circuit rather than in place: a published
     * article never changes silently (SPEC 5.4), the correction keeps
     * its snapshot, and the article carries its "corrected on" mention.
     * The revision is applied straight away, a version of its own editor
     * needing no review (SPEC 5.1).
     */
    private function refreshOutdated(Article $source): int
    {
        $done = 0;

        $outdated = Article::query()
            ->where('translation_group_id', $source->translation_group_id)
            ->where('is_source', false)
            ->where('auto_translated', true)
            ->where('status', ArticleStatus::PUBLISHED->value)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($source): void {
                $query->whereNull('source_revision_number')
                    ->orWhere('source_revision_number', '!=', $source->revision_number);
            })
            ->get();

        foreach ($outdated as $translation) {
            $author = $translation->author_user_id !== null
                ? User::query()->find($translation->author_user_id)
                : null;

            if ($author === null || $this->revisions->pendingRevision($translation) !== null) {
                continue;
            }

            $fields = $this->translateFields($source, $translation->locale);

            if ($fields === null) {
                continue;
            }

            $revision = $this->revisions->propose(
                $translation,
                $author,
                $fields,
                'Mise à jour de la traduction après correction de la version source.',
            );

            $this->revisions->apply($revision);
            $done++;
        }

        if ($done > 0) {
            Log::info('AutoTranslationService: versions refreshed', [
                'article_id' => $source->getKey(),
                'count' => $done,
            ]);
        }

        return $done;
    }

    /**
     * The three written fields, translated, or null when the engine
     * could not deliver all of them.
     *
     * All or nothing on purpose: a version with a translated title and a
     * French body is worse than no version at all, since the feed would
     * present it as the Spanish reading of the announcement.
     *
     * @return array<string, string>|null
     */
    private function translateFields(Article $source, string $locale): ?array
    {
        $fields = [];

        foreach (['title', 'summary', 'body'] as $field) {
            $translated = $this->engine->translate(
                (string) $source->{$field},
                $source->locale,
                $locale,
            );

            if ($translated === null) {
                Log::warning('AutoTranslationService: field left untranslated, version skipped', [
                    'article_id' => $source->getKey(),
                    'locale' => $locale,
                    'field' => $field,
                ]);

                return null;
            }

            $fields[$field] = $translated;
        }

        return $fields;
    }
}

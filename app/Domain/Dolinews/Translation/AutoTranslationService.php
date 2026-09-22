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
 * - the body travels in blocks, fenced code held out of the translation
 *   (MarkdownSegments): an announcement about a Dolibarr module carries
 *   configuration lines and commands that no engine must rewrite;
 * - a machine version is published like any version of its editor: at
 *   the date of its source, without review (SPEC 5.1).
 *
 * It is free, and has to be: SPEC 12 forbids charging for visibility,
 * and a translation makes an announcement appear in a language filter
 * where it was absent. Which engine serves which editor, and what is
 * left to spend, is the router's business (TranslationRouter).
 */
class AutoTranslationService
{
    public function __construct(
        private readonly TranslationRouter $router,
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
        if ($source->isTranslation() || $source->status !== ArticleStatus::PUBLISHED) {
            return 0;
        }

        $route = $this->router->resolve($source->editor);
        $engine = $route['engine'];

        if ($engine === null) {
            Log::info('AutoTranslationService: nothing to translate with', [
                'article_id' => $source->getKey(),
                'reason' => $route['reason'],
            ]);

            return 0;
        }

        $shared = $route['route'] === TranslationRouter::ROUTE_SHARED;

        return $this->fillMissingLocales($source, $engine, $shared)
            + $this->refreshOutdated($source, $engine, $shared);
    }

    /**
     * Translate one announcement into one language, on explicit demand.
     *
     * The screen of an editor that reads its own announcement, sees a
     * language missing and asks for it. Same production and same
     * publication as the automatic route - at the source's date, without
     * review (SPEC 5.1) - but triggered by a click rather than by a
     * publication.
     *
     * The editor's language selection does not bound it: that selection
     * says what happens by itself, not what may be asked for.
     *
     * @throws AutoTranslationException when there is no engine, no
     *                                  allowance left, the language is
     *                                  already there, or the engine
     *                                  answered nothing.
     */
    public function translateInto(Article $source, string $locale): Article
    {
        if ($source->isTranslation() || $source->status !== ArticleStatus::PUBLISHED) {
            throw new AutoTranslationException(
                'Seule une annonce publiée se traduit, et depuis sa version d\'origine.'
            );
        }

        /** @var array<int, string> $content */
        $content = (array) config('dolinews.content_locales', []);

        if (! in_array($locale, $content, true) || $locale === $source->locale) {
            throw new AutoTranslationException('Cette langue n\'est pas proposée pour cette annonce.');
        }

        if (in_array($locale, $source->translations()->pluck('locale')->all(), true)) {
            throw new AutoTranslationException('Cette annonce a déjà une version dans cette langue.');
        }

        $route = $this->router->resolve($source->editor, onDemand: true);
        $engine = $route['engine'];

        if ($engine === null) {
            throw new AutoTranslationException(match ($route['reason']) {
                TranslationRouter::REASON_QUOTA_SPENT => 'Le volume de traduction du mois est atteint : il est reconduit le mois prochain, ou vous pouvez renseigner votre propre clé.',
                default => 'La traduction automatique n\'est pas disponible pour cet éditeur.',
            });
        }

        $supported = $engine->supportedLocales();

        if ($supported !== [] && ! in_array($locale, $supported, true)) {
            throw new AutoTranslationException('Le moteur de traduction ne traite pas cette langue.');
        }

        $author = $source->author_user_id !== null
            ? User::query()->find($source->author_user_id)
            : null;

        if ($author === null) {
            throw new AutoTranslationException('Cette annonce n\'a plus d\'auteur : sa traduction ne peut pas être publiée en son nom.');
        }

        $fields = $this->translateFields(
            $source,
            $locale,
            $engine,
            $route['route'] === TranslationRouter::ROUTE_SHARED,
        );

        if ($fields === null) {
            throw new AutoTranslationException(
                'Le moteur de traduction n\'a rien renvoyé : rien n\'a été publié, vous pouvez réessayer.'
            );
        }

        $translation = $this->translations->submitTranslation($source, $author, $locale, $fields);
        $translation->auto_translated = true;
        $translation->save();

        $this->publisher->publish($translation, $source);

        Log::info('AutoTranslationService: version produced on demand', [
            'article_id' => $source->getKey(),
            'locale' => $locale,
        ]);

        return $translation;
    }

    /**
     * Whether automatic translation applies to this announcement right
     * now, engine and ceiling included.
     */
    public function appliesTo(Article $source): bool
    {
        if ($source->isTranslation() || $source->status !== ArticleStatus::PUBLISHED) {
            return false;
        }

        return $this->router->resolve($source->editor)['engine'] !== null;
    }

    /**
     * The locales this announcement has no version in yet, among those
     * the service publishes in, the engine handles, and the editor asked
     * for.
     *
     * Three filters and not one, because they answer three different
     * questions: what the service publishes in, what the engine can do,
     * and what the editor wants. An empty editor list means all of them,
     * which is the state an editor starts in.
     *
     * @return array<int, string>
     */
    public function missingLocales(Article $source, ?TranslationEngine $engine = null): array
    {
        $engine ??= $this->router->resolve($source->editor)['engine'];

        /** @var array<int, string> $content */
        $content = (array) config('dolinews.content_locales', []);
        $supported = $engine?->supportedLocales() ?? [];
        $wanted = $source->editor->translation_locales ?? [];

        $existing = $source->translations()->pluck('locale')->all();

        return array_values(array_filter(
            $content,
            static fn (string $locale): bool => $locale !== $source->locale
                && ! in_array($locale, $existing, true)
                && ($supported === [] || in_array($locale, $supported, true))
                && ($wanted === [] || in_array($locale, $wanted, true)),
        ));
    }

    /**
     * Produce and publish the versions the announcement lacks.
     */
    private function fillMissingLocales(Article $source, TranslationEngine $engine, bool $shared): int
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

        foreach ($this->missingLocales($source, $engine) as $locale) {
            $fields = $this->translateFields($source, $locale, $engine, $shared);

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
     *
     * The editor's language selection is NOT applied here. It says which
     * versions to produce, not which ones to keep correct: a version
     * already online in a language since removed from the list stays
     * online, and leaving it describing a text that changed would be
     * publishing something false on purpose.
     */
    private function refreshOutdated(Article $source, TranslationEngine $engine, bool $shared): int
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

            $fields = $this->translateFields($source, $translation->locale, $engine, $shared);

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
     * could not deliver them all.
     *
     * Title, summary and the blocks of the body leave in ONE call: an
     * engine billing per call, or caching per segment, works far better
     * that way, and the whole version is decided in one answer.
     *
     * All or nothing on purpose: a version with a translated title and a
     * French body is worse than no version at all, since the feed would
     * present it as the reading of the announcement in that language.
     *
     * @return array<string, string>|null
     */
    private function translateFields(
        Article $source,
        string $locale,
        TranslationEngine $engine,
        bool $shared,
    ): ?array {
        $segments = MarkdownSegments::split($source->body);
        $bodyTexts = $segments->translatableTexts();

        $batch = array_merge([$source->title, $source->summary], $bodyTexts);
        $characters = array_sum(array_map('mb_strlen', $batch));

        $translated = $engine->translateBatch($batch, $source->locale, $locale);

        if ($translated === null || count($translated) !== count($batch)) {
            Log::warning('AutoTranslationService: batch unanswered, version skipped', [
                'article_id' => $source->getKey(),
                'locale' => $locale,
            ]);

            return null;
        }

        // Booked after the answer: a call that produced no text cost the
        // service nothing, and must not spend an editor's ceiling.
        if ($shared) {
            $this->router->record($source->editor, $characters);
        }

        return [
            'title' => $translated[0],
            'summary' => $translated[1],
            'body' => $segments->reassemble(array_slice($translated, 2)),
        ];
    }
}

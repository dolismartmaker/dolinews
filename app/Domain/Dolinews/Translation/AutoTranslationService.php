<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Translation;

use App\Domain\Dolinews\Articles\RevisionService;
use App\Domain\Dolinews\Articles\TranslationPublisher;
use App\Domain\Dolinews\Articles\TranslationService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\Article;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
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
    /**
     * What the columns of `articles` hold (SPEC 4.3), which is also what
     * the submission forms accept from a human author.
     */
    private const TITLE_MAX = 255;

    private const SUMMARY_MAX = 500;

    /**
     * Below this, cutting at a sentence boundary throws away too much of
     * the summary to still be a summary.
     */
    private const SUMMARY_MIN_KEPT = 300;

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
     *
     * $force spends past the monthly allowance and nothing else, for
     * the operator's command line (SPEC 5.7). The editor's opt-in is
     * not among what it lifts.
     */
    public function sync(Article $source, bool $force = false): int
    {
        if ($source->isTranslation() || $source->status !== ArticleStatus::PUBLISHED) {
            return 0;
        }

        $route = $this->router->resolve($source->editor, ignoreCeiling: $force);
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
     * $force spends past the monthly allowance, which only the
     * operator's command line ever passes: the editor's own screen
     * never does, or the ceiling would be a formality (SPEC 5.7).
     *
     * @throws AutoTranslationException when there is no engine, no
     *                                  allowance left, the language is
     *                                  already there, or the engine
     *                                  answered nothing.
     */
    public function translateInto(Article $source, string $locale, bool $force = false): Article
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

        $route = $this->router->resolve($source->editor, onDemand: true, ignoreCeiling: $force);
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
     * Send one language version of an announcement to the shared engine
     * and throw the answer away.
     *
     * Not a translation: nothing is written, nothing is published, no
     * revision is proposed. It exists so the endpoint can be handed again
     * what it has already been handed - rebuilding its own corpus after a
     * reset, for instance - without producing a second version of texts
     * that are already online, which would mean a revision of each of
     * them for no editorial reason at all.
     *
     * Three bounds, for three different reasons:
     *
     * - the editor's opt-in still applies, checked by the caller. Nothing
     *   is published here, but the text does leave for a third party, and
     *   that is what the editor consented to;
     * - the SHARED engine, always, never the editor's own key: replaying
     *   on a third party's key would spend its money on our endpoint's
     *   corpus;
     * - the monthly ceiling does not apply and nothing is booked against
     *   it. It measures what an editor spends to appear (SPEC 5.7);
     *   nothing appears here, and the editor did not ask for it - the
     *   operator did.
     *
     * @return int|null characters sent, null when the engine answered
     *                  nothing or nothing usable
     *
     * @throws AutoTranslationException when the announcement is not a
     *                                  published source, the language is
     *                                  not one the service publishes in,
     *                                  or no shared engine is configured.
     */
    public function replay(Article $source, string $locale): ?int
    {
        if ($source->isTranslation() || $source->status !== ArticleStatus::PUBLISHED) {
            throw new AutoTranslationException(
                'Seule une annonce publiée se renvoie, et depuis sa version d\'origine.'
            );
        }

        /** @var array<int, string> $content */
        $content = (array) config('dolinews.content_locales', []);

        if (! in_array($locale, $content, true) || $locale === $source->locale) {
            throw new AutoTranslationException('Cette langue n\'est pas proposée pour cette annonce.');
        }

        $engine = $this->router->sharedEngine();

        if (! $engine->isAvailable()) {
            throw new AutoTranslationException('Aucun point d\'accès de traduction n\'est configuré.');
        }

        $batch = $this->outgoingBatch($source);
        $answer = $engine->translateBatch($batch, $source->locale, $locale);

        if ($answer === null || count($answer) !== count($batch)) {
            Log::warning('AutoTranslationService: replayed batch unanswered', [
                'article_id' => $source->getKey(),
                'locale' => $locale,
            ]);

            return null;
        }

        $characters = $this->countCharacters($batch);

        Log::info('AutoTranslationService: batch replayed, answer discarded', [
            'article_id' => $source->getKey(),
            'locale' => $locale,
            'characters' => $characters,
        ]);

        return $characters;
    }

    /**
     * The languages this announcement has already been sent to the engine
     * in: those of its machine versions.
     *
     * Every status and deleted ones included: what is looked for is the
     * call that was made, and a version withdrawn since was translated
     * all the same. Human translations are absent, never having been sent
     * anywhere.
     *
     * @return array<int, string>
     */
    public function replayableLocales(Article $source): array
    {
        /** @var array<int, string> $locales */
        $locales = Article::query()
            ->where('translation_group_id', $source->translation_group_id)
            ->where('is_source', false)
            ->where('auto_translated', true)
            ->orderBy('locale')
            ->pluck('locale')
            ->unique()
            ->values()
            ->all();

        return $locales;
    }

    /**
     * Characters one language version of this announcement sends to the
     * engine, which is what a dry run reports without sending anything.
     */
    public function outgoingCharacters(Article $source): int
    {
        return $this->countCharacters($this->outgoingBatch($source));
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
     * The machine versions of this announcement written against an older
     * source, those a refresh would rewrite.
     *
     * The editor's language selection is deliberately absent, for the
     * reason refreshOutdated() states: it says which versions to produce,
     * not which ones to keep correct.
     *
     * @return Collection<int, Article>
     */
    public function outdatedTranslations(Article $source): Collection
    {
        return Article::query()
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

        foreach ($this->outdatedTranslations($source) as $translation) {
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
        $batch = $this->outgoingBatch($source, $segments);
        $characters = $this->countCharacters($batch);

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

        // German and Polish run noticeably longer than French, so a
        // summary written just under the limit comes back over it. Left
        // unchecked the insert fails on the column width and takes the
        // whole run down with it.
        if (mb_strlen($translated[0]) > self::TITLE_MAX) {
            Log::warning('AutoTranslationService: translated title too long, version skipped', [
                'article_id' => $source->getKey(),
                'locale' => $locale,
                'length' => mb_strlen($translated[0]),
                'limit' => self::TITLE_MAX,
            ]);

            return null;
        }

        return [
            'title' => $translated[0],
            'summary' => $this->fitSummary($translated[1], $source, $locale),
            'body' => $segments->reassemble(array_slice($translated, 2)),
        ];
    }

    /**
     * The texts one language version sends to the engine, in the order
     * the answer comes back in: title, summary, then the blocks of the
     * body a fence does not hold out.
     *
     * @return array<int, string>
     */
    private function outgoingBatch(Article $source, ?MarkdownSegments $segments = null): array
    {
        $segments ??= MarkdownSegments::split($source->body);

        return array_merge([$source->title, $source->summary], $segments->translatableTexts());
    }

    /**
     * @param  array<int, string>  $batch
     */
    private function countCharacters(array $batch): int
    {
        return (int) array_sum(array_map('mb_strlen', $batch));
    }

    /**
     * A translated summary cut down to what the column holds.
     *
     * The title is skipped rather than shortened, a clipped title reading
     * as a sentence broken off wherever it appears. A summary is a text
     * written to be skimmed, where an ellipsis is a convention rather
     * than a defect, and the sentence that goes is most often the
     * compatibility note the Dolibarr range already states next to it.
     *
     * Cut at the last whole sentence that fits, so what is left reads as
     * it was written. When no sentence boundary leaves enough of the text
     * - a single long sentence, or a first one of three words - the cut
     * falls on the last word instead, and says so with an ellipsis.
     */
    private function fitSummary(string $summary, Article $source, string $locale): string
    {
        if (mb_strlen($summary) <= self::SUMMARY_MAX) {
            return $summary;
        }

        $head = mb_substr($summary, 0, self::SUMMARY_MAX);
        $fitted = null;

        // Greedy on purpose: the LAST boundary that fits. A decimal in
        // "1.0.15" is not one, the dot not being followed by a space.
        if (preg_match('/^.*[.!?](?=\s|$)/su', $head, $matches) === 1) {
            $sentences = rtrim($matches[0]);

            if (mb_strlen($sentences) >= self::SUMMARY_MIN_KEPT) {
                $fitted = $sentences;
            }
        }

        if ($fitted === null) {
            $word = mb_substr($head, 0, self::SUMMARY_MAX - 3);
            $space = mb_strrpos($word, ' ');

            if ($space !== false) {
                $word = mb_substr($word, 0, $space);
            }

            $fitted = rtrim($word, " \t\n\r,;:-").'...';
        }

        Log::info('AutoTranslationService: translated summary shortened to the column', [
            'article_id' => $source->getKey(),
            'locale' => $locale,
            'from' => mb_strlen($summary),
            'to' => mb_strlen($fitted),
        ]);

        return $fitted;
    }
}

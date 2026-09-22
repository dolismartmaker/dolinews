<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Articles;

use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\PublicationMode;
use App\Domain\Dolinews\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Publication of language versions (SPEC 5.1, settled 2026-09-22).
 *
 * A translation carries no quorum. The text it translates was reviewed
 * on its substance, and the metadata of a translation is copied from its
 * source rather than typed: what is left to judge is fidelity, which the
 * team cannot judge anyway in ten languages it does not read.
 *
 * Hence the split the service applies:
 *
 * - an editor translating its own announcement publishes directly. It
 *   answers for what goes out under its name, and asking three
 *   moderators to nod at a Greek text nobody reads is a ritual, not a
 *   safeguard;
 * - a mandated outsider (SPEC 5.6) goes through ONE reviewer, because
 *   the account writing is not the one the announcement belongs to.
 *
 * A translation never publishes before its source. Until the source is
 * out, the translation waits in the queue and leaves with it.
 *
 * It inherits the source's published_at, and this is not cosmetic: the
 * feed is ordered on that column, so a translation dated of its own
 * writing would pull a version out at a date it was not released at, and
 * the translations of a back-dated catalogue would land at the top of
 * the feed - and in the subscription mails - as so many fresh
 * announcements (SPEC 5.1, 6.4).
 */
class TranslationPublisher
{
    public function __construct(
        private readonly EditorService $editors,
    ) {}

    /**
     * Whether this article is a translation its own editor may publish
     * without any review: its source is out, and the account submitting
     * writes under the editor's identity.
     */
    public function publishesWithoutReview(Article $article, User $author): bool
    {
        if (! $article->isTranslation()) {
            return false;
        }

        $source = $article->sourceArticle();

        if ($source === null || $source->status !== ArticleStatus::PUBLISHED) {
            return false;
        }

        if ($source->author_user_id === $author->getKey()) {
            return true;
        }

        return $this->editors->isMember($article->editor, $author);
    }

    /**
     * Publish a translation at the date of the announcement it carries.
     *
     * Runs inside the caller's transaction.
     */
    public function publish(Article $translation, Article $source): Article
    {
        $translation->status = ArticleStatus::PUBLISHED;
        $translation->publication_mode = PublicationMode::TRANSLATION;
        $translation->published_at = $source->published_at;
        $translation->save();

        Log::info('TranslationPublisher: translation published with its source date', [
            'article_id' => $translation->getKey(),
            'source_id' => $source->getKey(),
            'locale' => $translation->locale,
        ]);

        return $translation;
    }

    /**
     * Publish the pending translations of a freshly published source
     * whose author writes under the editor's identity.
     *
     * The nominal case of an editor submitting a release and its nine
     * language versions in one go: the source leaves review, the
     * versions leave with it. A mandated outsider's translation is left
     * in the queue for its reviewer.
     *
     * Runs inside the caller's transaction.
     */
    public function publishPendingSiblings(Article $source): int
    {
        if ($source->isTranslation() || $source->status !== ArticleStatus::PUBLISHED) {
            return 0;
        }

        $published = 0;

        $pending = Article::query()
            ->where('translation_group_id', $source->translation_group_id)
            ->where('is_source', false)
            ->where('status', ArticleStatus::PENDING->value)
            ->whereNull('deleted_at')
            ->get();

        foreach ($pending as $translation) {
            $author = $translation->author_user_id !== null
                ? User::query()->find($translation->author_user_id)
                : null;

            if ($author === null || ! $this->publishesWithoutReview($translation, $author)) {
                continue;
            }

            $this->publish($translation, $source);
            $published++;
        }

        return $published;
    }
}

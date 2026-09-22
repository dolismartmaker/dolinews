<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Articles;

use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\ArticleRevision;
use App\Jobs\TranslateAnnouncement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Post-publication revisions (SPEC 5.4): a published article is never
 * modified in place, every change re-enters the review circuit like a
 * commit pushed after an approval.
 *
 * The complete article state before application is snapshotted, not
 * just the diff: rebuilding an original by replaying three diffs
 * backwards fails at the first mistake, and the past is contested
 * exactly when it is needed. Revisions do not consume the publication
 * quota: it is not a new announcement.
 */
class RevisionService
{
    /**
     * Propose a revision of a published article. Only one pending
     * revision per article (SPEC 5.4).
     *
     * @param  array<string, mixed>  $changes  changed fields only
     *
     * @throws ArticleException when the article is not published, the
     *                          author does not own it, no change is proposed, or a
     *                          revision is already pending.
     */
    public function propose(Article $article, User $author, array $changes, string $motive): ArticleRevision
    {
        if ($article->author_user_id !== $author->getKey()) {
            throw new ArticleException('Seul l\'auteur peut proposer une révision.');
        }

        if ($article->status !== ArticleStatus::PUBLISHED && $article->status !== ArticleStatus::HIDDEN) {
            throw new ArticleException('Seul un article publié se révise.');
        }

        $changes = $this->filterRevisionable($changes);

        if ($changes === []) {
            throw new ArticleException('Aucun champ modifié dans la révision proposée.');
        }

        if ($this->pendingRevision($article) !== null) {
            throw new ArticleException('Une révision est déjà en attente pour cet article.');
        }

        $revision = ArticleRevision::query()->create([
            'article_id' => $article->getKey(),
            'author_user_id' => $author->getKey(),
            'payload' => $changes,
            // The COMPLETE state before application, not just the diff
            // (SPEC 5.4): the original must stay consultable as it was.
            'snapshot' => $article->only([
                'title', 'summary', 'body', 'version', 'locale',
                'dolibarr_min', 'dolibarr_max', 'maturity',
                'compat_status', 'focus', 'revision_number',
            ]),
            'motive' => $motive,
            'status' => 'pending',
        ]);

        Log::info('RevisionService: revision proposed', [
            'article_id' => $article->getKey(),
            'revision_id' => $revision->getKey(),
            'fields' => array_keys($changes),
        ]);

        return $revision;
    }

    /**
     * Apply a pending revision: the article moves to the changed state,
     * the source's revision_number is incremented, which perimes its
     * translations (SPEC 5.4), and the article shows its correction
     * mention.
     */
    public function apply(ArticleRevision $revision): ArticleRevision
    {
        if (! $revision->isPending()) {
            throw new ArticleException('Cette révision a déjà été tranchée.');
        }

        return DB::transaction(function () use ($revision): ArticleRevision {
            /** @var Article $article */
            $article = $revision->article()->lockForUpdate()->firstOrFail();

            $article->fill($revision->payload);

            if ($article->isTranslation()) {
                // A translation revision puts its numbers back in phase
                // with the source: it was written against the source's
                // CURRENT revision (SPEC 5.4).
                $source = $article->sourceArticle();

                if ($source !== null) {
                    $article->source_revision_number = $source->revision_number;
                }
            } else {
                // The source's revision number is the peremption clock of
                // its translations: each applied revision moves it forward
                // (SPEC 5.4).
                $article->revision_number++;
            }

            $article->save();

            $revision->status = 'applied';
            $revision->decided_at = now();
            $revision->save();

            Log::info('RevisionService: revision applied', [
                'article_id' => $article->getKey(),
                'revision_id' => $revision->getKey(),
            ]);

            // A corrected source leaves its machine versions describing a
            // text that changed (SPEC 5.4): they are rewritten from the
            // new text, human translations untouched (SPEC 5.7). After
            // the commit, and never for a translation's own revision,
            // which would loop.
            if (! $article->isTranslation()) {
                $id = (int) $article->getKey();

                DB::afterCommit(static function () use ($id): void {
                    TranslateAnnouncement::dispatch($id);
                });
            }

            return $revision;
        });
    }

    /**
     * Reject a pending revision, motive in the review thread.
     */
    public function reject(ArticleRevision $revision): ArticleRevision
    {
        if (! $revision->isPending()) {
            throw new ArticleException('Cette révision a déjà été tranchée.');
        }

        $revision->status = 'rejected';
        $revision->decided_at = now();
        $revision->save();

        return $revision;
    }

    /**
     * The pending revision of an article, if any.
     */
    public function pendingRevision(Article $article): ?ArticleRevision
    {
        /** @var ArticleRevision|null $pending */
        $pending = $article->revisions()
            ->where('status', 'pending')
            ->first();

        return $pending;
    }

    /**
     * Fields a revision may change: content fields only, publication
     * state and review plumbing never.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function filterRevisionable(array $changes): array
    {
        $allowed = [
            'title', 'summary', 'body', 'version', 'dolibarr_min',
            'dolibarr_max', 'maturity', 'compat_status', 'focus',
        ];

        return array_intersect_key($changes, array_flip($allowed));
    }
}

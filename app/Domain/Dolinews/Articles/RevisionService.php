<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Articles;

use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\ArticleRevision;
use App\Domain\Dolinews\Models\Project;
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
    public function __construct(
        private readonly ArticleService $articles,
    ) {}

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

        // Checked here and not only on application: a revision refused by
        // the sheet it names would otherwise sit in the queue until a
        // moderator discovers, after reading it, that it cannot apply.
        if (array_key_exists('project_id', $changes)) {
            $this->assertSheetReachable($article, $changes['project_id']);
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
                'compat_status', 'focus', 'project_id', 'revision_number',
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

            $payload = $revision->payload;

            // The sheet is not filled in like the other fields: it is
            // borne by every article of the translation group, so it
            // moves the whole group at once (and refuses a slug already
            // taken at the destination).
            $filing = array_key_exists('project_id', $payload);
            $projectId = $filing && $payload['project_id'] !== null
                ? (int) $payload['project_id']
                : null;

            unset($payload['project_id']);

            $article->fill($payload);

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

            if ($filing) {
                $this->articles->linkProject(
                    $article,
                    $projectId === null ? null : Project::query()->findOrFail($projectId),
                );
            }

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
     * project_id is in there, which is how an announcement published
     * without its sheet gets filed by its own author: the operator has
     * the direct act (SPEC 9.4), the author has this one, and the review
     * sees the filing like any other change.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function filterRevisionable(array $changes): array
    {
        $allowed = [
            'title', 'summary', 'body', 'version', 'dolibarr_min',
            'dolibarr_max', 'maturity', 'compat_status', 'focus',
            'project_id',
        ];

        return array_intersect_key($changes, array_flip($allowed));
    }

    /**
     * Whether the sheet named by a revision can take the announcement.
     *
     * @throws ArticleException when it belongs to another editor, or when
     *                          a slug of the group is already taken there
     */
    private function assertSheetReachable(Article $article, mixed $projectId): void
    {
        $project = $projectId === null
            ? null
            : Project::query()->find((int) $projectId);

        if ($projectId !== null && $project === null) {
            throw new ArticleException('Cette fiche projet n\'existe pas.');
        }

        $group = $this->articles->translationGroup($article);

        foreach ($group as $member) {
            $this->articles->assertProjectLinkable($member, $project, $group->modelKeys());
        }
    }
}

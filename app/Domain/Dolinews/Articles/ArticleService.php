<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Articles;

use App\Core\Audit\AuditLogger;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\ArticleType;
use App\Domain\Dolinews\Enums\CompatStatus;
use App\Domain\Dolinews\Enums\Focus;
use App\Domain\Dolinews\Enums\Maturity;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Review\ReviewAudience;
use App\Models\User;
use App\Notifications\ArticleSubmitted;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Draft writing, editing and submission of feed articles (SPEC 5).
 *
 * A published article is never edited in place: modifications go through
 * RevisionService. An edit made while the article is pending counts as a
 * resubmission and resets the review accords (SPEC 5.1).
 */
class ArticleService
{
    public function __construct(
        private readonly PublicationQuotaService $quota,
        private readonly TranslationPublisher $translations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create a new draft (the source of a new translation group).
     *
     * The translation group id is assigned right away, even for a lone
     * original: nulls would escape the (group, locale) unique index and
     * the original would need linking after the fact (SPEC 4.3).
     *
     * @param  array<string, mixed>  $payload  validated article fields
     */
    public function createDraft(User $author, Editor $editor, array $payload): Article
    {
        $type = $payload['type'] instanceof ArticleType
            ? $payload['type']
            : ArticleType::from((string) $payload['type']);

        $article = Article::query()->create([
            'editor_id' => $editor->getKey(),
            'project_id' => $payload['project_id'] ?? null,
            'author_user_id' => $author->getKey(),
            'type' => $type,
            // focus only makes sense on a release: null on announcements
            // (SPEC 4.3).
            'focus' => $type === ArticleType::RELEASE ? ($payload['focus'] ?? null) : null,
            'title' => (string) $payload['title'],
            'slug' => $this->uniqueSlug(
                (string) $payload['title'],
                $payload['project_id'] ?? null,
            ),
            'version' => $payload['version'] ?? null,
            'summary' => (string) $payload['summary'],
            'body' => (string) $payload['body'],
            'locale' => (string) $payload['locale'],
            'translation_group_id' => (string) Str::uuid(),
            'is_source' => true,
            'revision_number' => 0,
            'source_revision_number' => null,
            'submission_seq' => 1,
            'dolibarr_min' => isset($payload['dolibarr_min']) ? (int) $payload['dolibarr_min'] : null,
            'dolibarr_max' => isset($payload['dolibarr_max']) ? (int) $payload['dolibarr_max'] : null,
            'maturity' => Maturity::from((string) ($payload['maturity'] ?? Maturity::STABLE->value)),
            'compat_status' => CompatStatus::from((string) ($payload['compat_status'] ?? CompatStatus::DECLARED->value)),
            'status' => ArticleStatus::DRAFT,
        ]);

        Log::info('ArticleService: draft created', [
            'article_id' => $article->getKey(),
            'author' => $author->getKey(),
        ]);

        return $article;
    }

    /**
     * Edit a draft or rejected article. Editing a pending article is a
     * resubmission (SPEC 5.1); published articles go through revisions.
     *
     * @param  array<string, mixed>  $payload  changed article fields
     */
    public function edit(Article $article, User $author, array $payload): Article
    {
        if ($article->author_user_id !== $author->getKey()) {
            throw new ArticleException('Seul l\'auteur peut modifier son article.');
        }

        if ($article->status === ArticleStatus::PUBLISHED || $article->status === ArticleStatus::HIDDEN) {
            throw new ArticleException(
                'Un article publié ne se modifie pas en place : proposez une révision.'
            );
        }

        $article->fill($this->filterEditable($payload));

        // focus stays null on announcements whatever the payload says.
        if ($article->type === ArticleType::ANNOUNCEMENT) {
            $article->focus = null;
        }

        if ($article->status === ArticleStatus::PENDING) {
            // An accord is an accord on a precise text: editing while
            // pending voids the accords already expressed by opening a
            // new review round (SPEC 5.1).
            $article->submitted_at = now();
            $article->submission_seq = $article->submission_seq + 1;
        }

        $article->save();

        return $article;
    }

    /**
     * Submit an article to the review queue (SPEC 5.1): reserves a
     * publication token and a queue slot, then moves the article to
     * pending. Rejected articles may be resubmitted, which also resets
     * the accords (the timestamp moves, decisions predating it stop
     * counting).
     */
    public function submit(Article $article, User $author): Article
    {
        if ($article->author_user_id !== $author->getKey()) {
            throw new ArticleException('Seul l\'auteur peut soumettre son article.');
        }

        if (! in_array($article->status, [ArticleStatus::DRAFT, ArticleStatus::REJECTED, ArticleStatus::PENDING], true)) {
            throw new ArticleException('Cet article n\'est pas soumissible en l\'état.');
        }

        // A language version its own editor publishes without review
        // (SPEC 5.1) draws on neither limit: it consumes no token, since
        // it is the same announcement in another language, and it never
        // sits in the queue a ceiling protects.
        $directPublication = $this->translations->publishesWithoutReview($article, $author);

        $article = DB::transaction(function () use ($article, $author, $directPublication): Article {
            if (! $directPublication) {
                $spared = $this->quota->assertSubmissionAllowed($article);

                // A security announcement passes a limit that would have
                // stopped anything else (SPEC 5.3, amended). Journalled
                // so the exemption can be counted: using the security
                // focus to jump the queue is a numbered breach (SPEC
                // 9.3), and a rule nobody can evidence is not applied.
                if ($spared !== null) {
                    $this->audit->log('article.security_quota_override', $article, [
                        'editor_id' => $article->editor_id,
                        'project_id' => $article->project_id,
                        'refusal_spared' => $spared,
                    ]);
                }
            }

            $article->status = ArticleStatus::PENDING;
            $article->submitted_at = now();

            // A resubmission opens a new review round: the accords of
            // the previous round stop counting, their trace stays
            // (SPEC 5.1). The round counter, not wall-clock comparison,
            // carries the semantics: two events in the same second are
            // routine under tests and in real bursts.
            $currentRound = $article->submission_seq >= 1 ? $article->submission_seq : 1;

            if ($article->reviewMessages()->exists()) {
                $currentRound++;
            }

            $article->submission_seq = $currentRound;
            $article->save();

            Log::info('ArticleService: submitted to review', [
                'article_id' => $article->getKey(),
                'author' => $author->getKey(),
                'round' => $article->submission_seq,
            ]);

            if ($directPublication) {
                $source = $article->sourceArticle();

                if ($source !== null) {
                    $this->translations->publish($article, $source);
                }
            }

            return $article;
        });

        // Nothing waits for a reviewer here, so nobody is told to come
        // and read it.
        if ($article->status === ArticleStatus::PUBLISHED) {
            return $article;
        }

        // After the commit on purpose, and after the OUTERMOST one: a
        // rollback must not leave the team notified of a submission
        // that never happened, and the API wraps this call in its own
        // transaction to undo a draft its quota refused. The author is
        // left out even when they moderate, as they never count in
        // their own quorum (SPEC 5.1), and the team is narrowed to the
        // moderators who read the article's language (ReviewAudience).
        DB::afterCommit(static function () use ($article, $author): void {
            app(ReviewAudience::class)
                ->forArticle($article, (int) $author->getKey())
                ->each(fn (User $moderator) => $moderator->notify(
                    new ArticleSubmitted($article, $author),
                ));
        });

        return $article;
    }

    /**
     * File an announcement under a project sheet, or take it out of one.
     *
     * An operator act, not an edit: not a word of the text changes, the
     * announcement is filed. It therefore carries no correction mention,
     * moves no revision number and costs no quota.
     *
     * Applied to the whole translation group, because project_id is borne
     * by each article: a sheet listing the French version while its nine
     * translations stay outside would be worse than no link at all.
     *
     * @return int the number of articles filed
     *
     * @throws ArticleException when the sheet belongs to another editor,
     *                          or when a slug of the group is already taken there
     */
    public function linkProject(Article $article, ?Project $project): int
    {
        $group = $this->translationGroup($article);

        foreach ($group as $member) {
            $this->assertProjectLinkable($member, $project, $group->modelKeys());
        }

        return DB::transaction(function () use ($group, $project): int {
            foreach ($group as $member) {
                $member->project_id = $project?->getKey();
                $member->save();
            }

            Log::info('ArticleService: announcement filed', [
                'translation_group_id' => $group->first()?->translation_group_id,
                'project_id' => $project?->getKey(),
                'articles' => $group->count(),
            ]);

            return $group->count();
        });
    }

    /**
     * Whether this article may be filed under this sheet.
     *
     * Two conditions. The sheet belongs to the same editor: filing is a
     * tidying act, where moving an announcement under someone else's
     * sheet would be a claim, and a claim has its own circuit (SPEC 9.5).
     * And the slug stays free in its new scope, (project_id, slug) being
     * unique: the slug is part of the API contract (it is what a client
     * filters on), so it is never silently rewritten to make room.
     *
     * @param  array<int, int|string>  $excludedIds  the group being filed along
     *
     * @throws ArticleException when one of the two fails
     */
    public function assertProjectLinkable(Article $article, ?Project $project, array $excludedIds = []): void
    {
        if ($project !== null && $project->editor_id !== $article->editor_id) {
            throw new ArticleException(
                'Cette fiche appartient à un autre éditeur : rattacher n\'est pas revendiquer.'
            );
        }

        $query = Article::query()
            ->where('slug', $article->slug)
            ->whereKeyNot($article->getKey());

        if ($excludedIds !== []) {
            $query->whereNotIn('id', $excludedIds);
        }

        if ($project === null) {
            $query->whereNull('project_id');
        } else {
            $query->where('project_id', $project->getKey());
        }

        if ($query->exists()) {
            throw new ArticleException(sprintf(
                'Le slug "%s" est déjà pris à cette destination : renommez l\'un des deux articles avant de rattacher.',
                $article->slug,
            ));
        }
    }

    /**
     * The announcement and all its language versions.
     *
     * @return Collection<int, Article>
     */
    public function translationGroup(Article $article): Collection
    {
        /** @var Collection<int, Article> $group */
        $group = Article::query()
            ->where('translation_group_id', $article->translation_group_id)
            ->orderBy('id')
            ->get();

        return $group;
    }

    /**
     * Build a slug unique within its scope: per project for project-bound
     * articles (UNIQUE (project_id, slug)), globally for project-less
     * announcements whose slugs escape any index on nullable columns:
     * collisions get an automatic numeric suffix (SPEC 4.3).
     */
    public function uniqueSlug(string $title, ?int $projectId): string
    {
        $base = Str::slug(mb_substr($title, 0, 80));

        if ($base === '') {
            $base = 'annonce';
        }

        $slug = $base;
        $suffix = 1;

        while ($this->slugTaken($slug, $projectId)) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }

    /**
     * Whether a slug is already used in its scope. Project-bound slugs
     * are compared against the same project only.
     */
    private function slugTaken(string $slug, ?int $projectId): bool
    {
        $query = Article::query()->where('slug', $slug);

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        } else {
            // Project-less announcements: global uniqueness, app-enforced
            // (SPEC 4.3). The unique index does not cover them since
            // project_id is null for all of them.
            $query->whereNull('project_id');
        }

        return $query->exists();
    }

    /**
     * Fields an author may change directly: published-article fields
     * (status, publication_mode, timestamps) never pass through edits.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function filterEditable(array $payload): array
    {
        $allowed = [
            'title', 'summary', 'body', 'version', 'locale',
            'dolibarr_min', 'dolibarr_max', 'maturity', 'compat_status',
            'focus', 'type', 'project_id',
        ];

        return array_intersect_key($payload, array_flip($allowed));
    }
}

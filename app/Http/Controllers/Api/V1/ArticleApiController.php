<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Core\Enums\ApiErrorCode;
use App\Core\Http\BaseApiController;
use App\Domain\Dolinews\Articles\ArticleException;
use App\Domain\Dolinews\Articles\ArticleService;
use App\Domain\Dolinews\Articles\QuotaException;
use App\Domain\Dolinews\Articles\RevisionService;
use App\Domain\Dolinews\Articles\TranslationService;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Enums\Maturity;
use App\Domain\Dolinews\Feeds\FeedService;
use App\Domain\Dolinews\Markdown\ArticleMarkdown;
use App\Domain\Dolinews\Media\MediaService;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Http\Controllers\Concerns\ResolvesUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Public API: reading the feed and submitting articles (SPEC 5.2).
 *
 * A token grants the right to SUBMIT, never to publish: every API
 * submission enters the review circuit like any other (SPEC 5.1/D12).
 */
class ArticleApiController extends BaseApiController
{
    use ResolvesUser;

    public function __construct(
        private readonly FeedService $feeds,
        private readonly ArticleService $articles,
        private readonly TranslationService $translations,
        private readonly RevisionService $revisions,
        private readonly EditorService $editorService,
        private readonly ArticleMarkdown $markdown,
        private readonly MediaService $media,
    ) {}

    /**
     * GET /api/v1/articles : the published feed, filterable exactly like
     * the web surface (SPEC 6.1).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'editor' => $request->input('editor'),
            'project' => $request->input('project'),
            'dolibarr' => $request->filled('dolibarr') ? (int) $request->input('dolibarr') : null,
            'focus' => $request->input('focus'),
            'locale' => $request->input('locale'),
            'maturities' => $this->maturities($request),
            // Same free-text filter as the web surface: a third-party
            // client must be able to ask the question a reader asks.
            'search' => $request->filled('q')
                ? mb_substr(trim((string) $request->input('q')), 0, 100)
                : null,
        ];

        $paginator = $this->feeds
            ->publicQuery($filters)
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        return $this->ok(
            $paginator->getCollection()
                ->map(fn (Article $article): array => $this->articlePayload($article))
                ->all(),
            [
                'current_page' => $paginator->currentPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        );
    }

    /**
     * GET /api/v1/articles/{id} : one published article.
     */
    public function show(int $id): JsonResponse
    {
        /** @var Article|null $article */
        $article = Article::query()
            ->published()
            ->with(['editor', 'project'])
            ->find($id);

        if ($article === null) {
            return $this->error(ApiErrorCode::NOT_FOUND);
        }

        $payload = $this->articlePayload($article);
        $payload['body_html'] = $this->markdown->render($article->body);
        $payload['translations'] = collect($this->translations->publishedSiblings($article))
            ->map(static fn (Article $sibling): array => [
                'locale' => $sibling->locale,
                'id' => $sibling->getKey(),
                'stale' => $sibling->isStaleTranslation(),
            ])
            ->all();

        return $this->ok($payload);
    }

    /**
     * POST /api/v1/articles : create a draft, optionally submitted right
     * away (SPEC 5.2). Illustrations come from previously deposited
     * media ids: the two-step publication.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        if (! $user->isContributor()) {
            return $this->error(ApiErrorCode::CONTRIBUTOR_REQUIRED);
        }

        $payload = $this->validatedPayload($request);

        $editor = $this->resolveEditor($user, (int) ($payload['editor_id'] ?? 0));

        if ($editor === null) {
            return $this->error(ApiErrorCode::FORBIDDEN, [
                'editor_id' => 'Editeur inconnu ou non membre.',
            ]);
        }

        try {
            // Creation and submission are one act for the caller, so
            // they are one transaction here: a quota refusal used to
            // answer 429 while leaving the draft behind, and a client
            // that retried once the queue cleared piled up duplicates
            // of an article it believed was never created (SPEC 5.3).
            $article = DB::transaction(function () use ($request, $user, $editor, $payload): Article {
                $article = $this->articles->createDraft($user, $editor, $payload);

                // Step two of the illustrated publication: the media
                // deposited beforehand belong to this article from now
                // on, and escape the orphan purge (SPEC 5.2).
                $this->bindMedia($payload, $article);

                if ($request->boolean('submit')) {
                    $this->articles->submit($article, $user);
                }

                return $article;
            });
        } catch (QuotaException $e) {
            return $this->quotaRefusal($e);
        } catch (ArticleException $e) {
            return $this->error(ApiErrorCode::CONFLICT, ['reason' => $e->getMessage()]);
        }

        return $this->created($this->articlePayload($article->refresh()));
    }

    /**
     * PATCH /api/v1/articles/{id} : edit a draft or rejected article; a
     * pending edit counts as a resubmission (SPEC 5.1).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->requireUser($request);

        /** @var Article|null $article */
        $article = Article::query()->find($id);

        if ($article === null || $article->author_user_id !== $user->getKey()) {
            return $this->error(ApiErrorCode::NOT_FOUND);
        }

        $payload = $this->validatedPayload($request, false);

        try {
            $this->articles->edit($article, $user, $payload);
            // An edit may add illustrations to a draft: same binding as
            // at creation.
            $this->bindMedia($payload, $article);
        } catch (ArticleException $e) {
            return $this->error(ApiErrorCode::CONFLICT, ['reason' => $e->getMessage()]);
        }

        return $this->ok($this->articlePayload($article->refresh()));
    }

    /**
     * POST /api/v1/articles/{id}/submit : enter the review queue.
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $user = $this->requireUser($request);

        /** @var Article|null $article */
        $article = Article::query()->find($id);

        if ($article === null || $article->author_user_id !== $user->getKey()) {
            return $this->error(ApiErrorCode::NOT_FOUND);
        }

        try {
            $this->articles->submit($article, $user);
        } catch (QuotaException $e) {
            return $this->quotaRefusal($e);
        } catch (ArticleException $e) {
            return $this->error(ApiErrorCode::CONFLICT, ['reason' => $e->getMessage()]);
        }

        return $this->ok($this->articlePayload($article->refresh()));
    }

    /**
     * POST /api/v1/articles/{id}/translations : submit a translated
     * version of an existing group (SPEC 5.2, D14).
     */
    public function storeTranslation(Request $request, int $id): JsonResponse
    {
        $user = $this->requireUser($request);

        if (! $user->isContributor()) {
            return $this->error(ApiErrorCode::CONTRIBUTOR_REQUIRED);
        }

        /** @var Article|null $source */
        $source = Article::query()->find($id);

        if ($source === null) {
            return $this->error(ApiErrorCode::NOT_FOUND);
        }

        $payload = $request->validate([
            'locale' => ['required', 'string', 'size:5'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:65535'],
            'submit' => ['nullable', 'boolean'],
        ]);

        // A translation carries the source's editor identity: its
        // author, a member of that editor, or an account the editor
        // mandated for that language (SPEC 5.6). The service refuses
        // too, this is here for the right status code -- hence the check
        // after validation, the mandate being granted per language.
        if (! $this->translations->canTranslate($source, $user, (string) $payload['locale'])) {
            return $this->error(ApiErrorCode::FORBIDDEN);
        }

        try {
            $translation = $this->translations->submitTranslation(
                $source,
                $user,
                (string) $payload['locale'],
                $payload,
            );

            if ($request->boolean('submit')) {
                $this->articles->submit($translation, $user);
            }
        } catch (ArticleException $e) {
            return $this->error(ApiErrorCode::CONFLICT, ['reason' => $e->getMessage()]);
        } catch (QuotaException $e) {
            return $this->quotaRefusal($e);
        }

        return $this->created($this->articlePayload($translation));
    }

    /**
     * POST /api/v1/articles/{id}/revisions : propose a correction of a
     * published article, re-entering the review circuit (SPEC 5.4).
     */
    public function storeRevision(Request $request, int $id): JsonResponse
    {
        $user = $this->requireUser($request);

        /** @var Article|null $article */
        $article = Article::query()->find($id);

        if ($article === null || $article->author_user_id !== $user->getKey()) {
            return $this->error(ApiErrorCode::NOT_FOUND);
        }

        $payload = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:65535'],
            'motive' => ['required', 'string', 'max:255'],
        ]);

        $changes = array_filter(
            $payload,
            static fn ($value, string $key): bool => in_array($key, ['title', 'summary', 'body'], true)
                && is_string($value) && trim($value) !== '',
            ARRAY_FILTER_USE_BOTH,
        );

        try {
            $revision = $this->revisions->propose($article, $user, $changes, (string) $payload['motive']);
        } catch (ArticleException $e) {
            return $this->error(ApiErrorCode::CONFLICT, ['reason' => $e->getMessage()]);
        }

        return $this->created([
            'revision_id' => $revision->getKey(),
            'status' => $revision->status,
            'changed_fields' => array_keys($revision->payload),
        ]);
    }

    /**
     * The frozen wire shape of one article. Dates keep the explicit
     * Y-m-d H:i:s format, the contract clients depend on (S12).
     *
     * @return array<string, mixed>
     */
    private function articlePayload(Article $article): array
    {
        return [
            'id' => $article->getKey(),
            'type' => $article->type->value,
            'focus' => $article->focus?->value,
            'title' => $article->title,
            'slug' => $article->slug,
            'version' => $article->version,
            'summary' => $article->summary,
            'locale' => $article->locale,
            'translation_group_id' => $article->translation_group_id,
            'is_source' => $article->is_source,
            'revision_number' => $article->revision_number,
            'source_revision_number' => $article->source_revision_number,
            'stale_translation' => $article->isStaleTranslation(),
            'dolibarr_min' => $article->dolibarr_min,
            'dolibarr_max' => $article->dolibarr_max,
            'maturity' => $article->maturity->value,
            'compat_status' => $article->compat_status->value,
            'status' => $article->status->value,
            'publication_mode' => $article->publication_mode?->value,
            'editor' => $article->editor->only(['id', 'slug', 'name']),
            'project' => $article->project?->only(['id', 'slug', 'name']),
            'submitted_at' => $article->submitted_at?->format('Y-m-d H:i:s'),
            'published_at' => $article->published_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Bind the media deposited at step one to the article that
     * references them (SPEC 5.2).
     *
     * Ids belonging to another editor, or already bound elsewhere, are
     * left out by the service: the gap is reported rather than silently
     * swallowed, because the consequence is a purged image and a broken
     * article twenty-four hours later.
     *
     * @param  array<string, mixed>  $payload
     */
    private function bindMedia(array $payload, Article $article): void
    {
        $ids = array_values(array_map(
            static fn ($id): int => (int) $id,
            (array) ($payload['media_ids'] ?? []),
        ));

        if ($ids === []) {
            return;
        }

        $bound = $this->media->bindToArticle($ids, $article);

        if ($bound < count($ids)) {
            Log::warning('ArticleApiController: media ids left unbound', [
                'article_id' => $article->getKey(),
                'editor_id' => $article->editor_id,
                'requested' => count($ids),
                'bound' => $bound,
            ]);
        }
    }

    /**
     * Resolve the editor to publish for: the account must be a member.
     */
    private function resolveEditor(User $user, int $editorId): ?Editor
    {
        /** @var Editor|null $editor */
        $editor = Editor::query()->find($editorId);

        if ($editor === null) {
            return null;
        }

        return $this->editorService->isMember($editor, $user) ? $editor : null;
    }

    /**
     * @return list<string>|null
     */
    private function maturities(Request $request): ?array
    {
        if (! $request->filled('maturity')) {
            return null;
        }

        $requested = (array) $request->input('maturity');
        $valid = array_values(array_filter(
            array_map('strval', $requested),
            static fn (string $value): bool => Maturity::tryFrom($value) !== null,
        ));

        return $valid === [] ? null : $valid;
    }

    /**
     * Name the quota rule that refused, so the caller knows what to wait
     * for: the ceiling clears as the team reviews, the bucket only with
     * time (SPEC 5.3).
     */
    private function quotaRefusal(QuotaException $exception): JsonResponse
    {
        return $this->error(
            $exception->queueCeiling
                ? ApiErrorCode::QUEUE_CEILING_REACHED
                : ApiErrorCode::QUOTA_BUCKET_EMPTY,
            ['reason' => $exception->getMessage()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, bool $withEditor = true): array
    {
        $rules = [
            'type' => ['required', 'in:release,announcement'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'focus' => ['nullable', 'in:doc,cleanup,feature_minor,feature_major,bugfix_minor,bugfix_major,security,compat,eol'],
            'title' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:32'],
            'summary' => ['required', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:65535'],
            'locale' => ['required', 'string', 'size:5'],
            'dolibarr_min' => ['nullable', 'integer', 'between:1,99'],
            'dolibarr_max' => ['nullable', 'integer', 'between:1,99'],
            'maturity' => ['nullable', 'in:alpha,beta,rc,stable,deprecated'],
            'compat_status' => ['nullable', 'in:declared,tested,experimental'],
            // Identifiers returned by POST /media: the body references
            // their URLs, this list ties them to the article (SPEC 5.2).
            'media_ids' => ['nullable', 'array', 'max:30'],
            'media_ids.*' => ['integer', 'exists:media,id'],
            'submit' => ['nullable', 'boolean'],
        ];

        if ($withEditor) {
            $rules['editor_id'] = ['required', 'integer', 'exists:editors,id'];
        }

        return $request->validate($rules);
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\Dolinews\Articles\TranslationService;
use App\Domain\Dolinews\Attestations\AttestationService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Enums\PublicationMode;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Attestation;
use App\Domain\Dolinews\Seo\ArticleUrl;
use App\Mcp\ReadingPayload;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * One published announcement in full, with what its page says around
 * the text (SPEC 5.4, 5.7, 5.8, 10): correction, translation, drafting
 * and bootstrap mentions, the tests published for that version.
 */
#[Name('get_announcement')]
#[Description(<<<'TEXT'
    Read one published DoliNews announcement in full: its Markdown body, its other language versions, its correction if it was corrected after publication, and the tests its editor published for that version.
    The Dolibarr range and maturity are what the editor announced on the announcement date, not the current state of the module.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetAnnouncement extends Tool
{
    /**
     * Longest body returned. An announcement is a release note, not a
     * manual; past this, the reader is sent to the page.
     */
    private const BODY_MAX = 20000;

    public function __construct(
        private readonly TranslationService $translations,
        private readonly AttestationService $attestations,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $input = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
            'locale' => ['nullable', 'string', 'max:10'],
        ]);

        $locale = ReadingPayload::locale($input['locale'] ?? null);

        /** @var Article|null $article */
        $article = Article::query()
            ->published()
            ->with(['editor', 'project', ...Article::IMAGE_RELATIONS])
            ->find((int) $input['id']);

        if ($article === null) {
            return Response::error('No published announcement has the id '.(int) $input['id'].'.');
        }

        return ReadingPayload::under($locale, function () use ($article, $locale): ResponseFactory {
            $body = $article->body;
            $truncated = mb_strlen($body) > self::BODY_MAX;

            $data = ReadingPayload::announcement($article, $locale) + [
                'body_markdown' => $truncated ? mb_substr($body, 0, self::BODY_MAX) : $body,
                'body_truncated' => $truncated,
                'image_url' => $article->featuredImage()?->url(),
                'editor_url' => ReadingPayload::editorUrl($article->editor, $locale),
                'project_url' => $article->project === null
                    ? null
                    : ReadingPayload::projectUrl($article->project, $locale),
                'versions' => array_map(
                    static fn (Article $sibling): array => [
                        'id' => $sibling->getKey(),
                        'locale' => $sibling->locale,
                        'url' => ArticleUrl::for($sibling),
                    ],
                    $this->translations->publishedSiblings($article),
                ),
                'mentions' => $this->mentions($article),
                // Tests ran on the version, not on one of its texts: the
                // same attestations on every language version. A masked
                // sheet takes them along (SPEC 10).
                'attestations' => $article->project === null
                    ? []
                    : $this->attestations->forArticle($article)
                        ->map(static fn (Attestation $attestation): array => ReadingPayload::attestation($attestation))
                        ->values()
                        ->all(),
            ];

            return Response::structured(ReadingPayload::envelope($data));
        });
    }

    /**
     * What the page says around the text, in the reader's language.
     *
     * @return list<string>
     */
    private function mentions(Article $article): array
    {
        $mentions = [];

        if ($article->publication_mode === PublicationMode::BOOTSTRAP) {
            $mentions[] = __('publié pendant l\'amorçage du service, avant constitution de l\'équipe de modération');
        }

        $source = $article->sourceArticle();

        // A language version leads to the text it translates, and never
        // silently poses as current once the source moved (SPEC 5.4).
        if ($source !== null && $source->status === ArticleStatus::PUBLISHED) {
            $mentions[] = ($article->isStaleTranslation()
                ? __('Cette traduction a été établie d\'après une version antérieure de l\'annonce.')
                : __('Cette annonce est une traduction.'))
                .' '.__('Lire la version d\'origine').' : '.ArticleUrl::for($source);
        }

        if ($article->auto_drafted) {
            $mentions[] = __('Texte établi automatiquement à partir du journal des modifications de la version, puis relu par la revue avant publication.');
        }

        // A dated feed never rewrites its past silently (SPEC 5.4).
        $correction = $article->lastAppliedRevision();

        if ($correction !== null) {
            $mentions[] = __('Corrigé le').' '.$correction->decided_at?->format('Y-m-d').' - '.$correction->motive;
        }

        return $mentions;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->min(1)->required()
                ->description('Announcement id, as returned by search_announcements.'),
            'locale' => $schema->string()
                ->enum(ReadingPayload::locales())
                ->description('Language of the reader, for the labels and mentions. Default: en. The text itself is the one of this announcement id; "versions" lists the other languages.'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\Dolinews\Feeds\FeedService;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Attestation;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\ProjectLink;
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
 * One project sheet, as its public page shows it (SPEC 4.2).
 *
 * The persistent half of what the service carries (D1): a presentation,
 * links, and under it the dated half - the newest stable version
 * announced, with its date, and the latest announcements.
 */
#[Name('get_project')]
#[Description(<<<'TEXT'
    Read one DoliNews project sheet: description, licence, links (Dolistore, shop, documentation, repository, test reports...), the newest stable version announced with its date, the latest announcements, and the tests its editor published.
    The sheet states no compatibility. "latest_stable_announced" is the newest stable version announced here, on the date given: the editor may have released later versions without announcing them.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetProject extends Tool
{
    private const RECENT_ANNOUNCEMENTS = 10;

    private const RECENT_ATTESTATIONS = 5;

    public function __construct(
        private readonly FeedService $feeds,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $input = $request->validate([
            'slug' => ['required', 'string', 'max:100'],
            'locale' => ['nullable', 'string', 'max:10'],
        ]);

        $locale = ReadingPayload::locale($input['locale'] ?? null);

        /** @var Project|null $project */
        $project = Project::query()
            ->with(['links', 'translations', 'editor', 'logo'])
            ->where('slug', (string) $input['slug'])
            ->first();

        // A masked sheet answers like an absent one: saying "masked"
        // would still publish that it exists (SPEC 4.2).
        if ($project === null || $project->isHidden()) {
            return Response::error('No project sheet has the slug "'.(string) $input['slug'].'". Use find_projects to look for it.');
        }

        return ReadingPayload::under($locale, function () use ($project, $locale): ResponseFactory {
            $translation = ReadingPayload::sheetTranslation($project, $locale);
            $latest = $this->feeds->latestStable($project, $locale);

            $data = ReadingPayload::project($project, $locale) + [
                'description_markdown' => $translation !== null && $translation->description !== null
                    ? $translation->description
                    : $project->description,
                'license' => $project->license,
                'logo_url' => $project->logo?->url() ?? $project->editor?->logo?->url(),
                'links' => $project->links
                    ->map(static fn (ProjectLink $link): array => [
                        'type' => $link->type->value,
                        'label' => $link->label ?? $link->type->label(),
                        'url' => $link->url,
                        'broken' => $link->is_broken,
                    ])
                    ->values()
                    ->all(),
                'latest_stable_announced' => $latest === null ? null : [
                    'id' => $latest->getKey(),
                    'version' => $latest->version,
                    'announced_on' => $latest->published_at?->format('Y-m-d'),
                    'url' => ArticleUrl::for($latest),
                ],
                // One version per announcement, in the reader's language
                // when it exists, every maturity: the sheet is where the
                // project is the whole subject (SPEC 6.1).
                'recent_announcements' => array_map(
                    static fn (Article $article): array => ReadingPayload::announcement($article, $locale),
                    $this->feeds->localeSlice(
                        $project->articles()
                            ->published()
                            ->with(['editor', 'project'])
                            ->orderByDesc('published_at')
                            ->getQuery(),
                        $locale,
                        self::RECENT_ANNOUNCEMENTS,
                    ),
                ),
                'attestations' => $project->attestations()
                    ->orderByDesc('received_at')
                    ->limit(self::RECENT_ATTESTATIONS)
                    ->get()
                    ->map(static fn (Attestation $attestation): array => ReadingPayload::attestation($attestation))
                    ->values()
                    ->all(),
            ];

            return Response::structured(ReadingPayload::envelope($data));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->required()
                ->description('Slug of the project sheet, as returned by find_projects or search_announcements.'),
            'locale' => $schema->string()
                ->enum(ReadingPayload::locales())
                ->description('Language of the reader: the sheet and the announcements are given in it when a version exists. Default: en.'),
        ];
    }
}

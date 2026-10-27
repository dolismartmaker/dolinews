<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\Dolinews\Feeds\FeedService;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
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
 * One editor page (SPEC 4.1/6.4): who publishes, its sheets and its
 * latest announcements. The contact address is never served: it is a
 * personal datum held for the moderation team (SPEC 9.8).
 */
#[Name('get_editor')]
#[Description(<<<'TEXT'
    Read one DoliNews editor: the organisation or person publishing announcements, its website, its project sheets and its latest announcements.
    "verified" means the moderation team validated the editor; it says nothing about the quality of its modules.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetEditor extends Tool
{
    private const RECENT_ANNOUNCEMENTS = 10;

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

        /** @var Editor|null $editor */
        $editor = Editor::query()->with('logo')->where('slug', (string) $input['slug'])->first();

        if ($editor === null) {
            return Response::error('No editor has the slug "'.(string) $input['slug'].'". Use find_projects to look for it by name.');
        }

        return ReadingPayload::under($locale, function () use ($editor, $locale): ResponseFactory {
            $data = [
                'slug' => $editor->slug,
                'name' => $editor->name,
                'description' => $editor->description,
                'website' => $editor->website,
                'verified' => $editor->verified_at !== null,
                'verified_label' => $editor->verified_at !== null ? __('éditeur validé') : null,
                'logo_url' => $editor->logo?->url(),
                'url' => ReadingPayload::editorUrl($editor, $locale),
                'projects' => $editor->projects()
                    ->visible()
                    ->with(['editor', 'translations'])
                    ->orderBy('name')
                    ->get()
                    ->map(static fn (Project $project): array => ReadingPayload::project($project, $locale))
                    ->values()
                    ->all(),
                'recent_announcements' => array_map(
                    static fn (Article $article): array => ReadingPayload::announcement($article, $locale),
                    $this->feeds->localeSlice(
                        $editor->articles()
                            ->published()
                            ->with(['editor', 'project'])
                            ->orderByDesc('published_at')
                            ->getQuery(),
                        $locale,
                        self::RECENT_ANNOUNCEMENTS,
                    ),
                ),
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
                ->description('Slug of the editor, as returned by find_projects or search_announcements.'),
            'locale' => $schema->string()
                ->enum(ReadingPayload::locales())
                ->description('Language of the reader. Default: en.'),
        ];
    }
}

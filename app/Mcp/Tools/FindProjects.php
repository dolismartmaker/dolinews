<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Search\SearchService;
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
 * The project sheets, searched or listed (SPEC 4.2/6.1).
 *
 * A reader knows a function - "Factur-X", "caisse" - and not a slug: the
 * search matches the sheet text and the editor's name, as the site's
 * does. Masked sheets are absent, as everywhere public.
 */
#[Name('find_projects')]
#[Description(<<<'TEXT'
    Find project sheets on DoliNews: the Dolibarr modules and services its editors announce. Search by words (name, summary, description, editor name) or list the sheets of one editor, alphabetically.
    A sheet carries no Dolibarr compatibility: what was announced, and when, is in the announcements (search_announcements with the project slug). A module absent here may exist: its editor may not publish on DoliNews.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class FindProjects extends Tool
{
    private const PER_PAGE = 25;

    /**
     * How many matching sheets a search reads before the editor filter
     * applies. The search caps its own result, so filtering after it on
     * a small cap would miss the editor's sheets further down.
     */
    private const SEARCH_WINDOW = 200;

    public function __construct(
        private readonly SearchService $search,
    ) {}

    public function handle(Request $request): ResponseFactory
    {
        $input = $request->validate([
            'query' => ['nullable', 'string', 'max:100'],
            'editor' => ['nullable', 'string', 'max:100'],
            'locale' => ['nullable', 'string', 'max:10'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $locale = ReadingPayload::locale($input['locale'] ?? null);
        $search = isset($input['query']) ? trim((string) $input['query']) : '';
        $editor = isset($input['editor']) ? (string) $input['editor'] : null;
        $page = (int) ($input['page'] ?? 1);

        return ReadingPayload::under($locale, function () use ($search, $editor, $page, $locale): ResponseFactory {
            if ($search !== '') {
                $matches = $this->search->projects($search, self::SEARCH_WINDOW)
                    ->filter(static fn (Project $project): bool => $editor === null || $project->editor?->slug === $editor)
                    ->values();

                $projects = $matches->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values();
                $total = $matches->count();
                $hasMore = $total > $page * self::PER_PAGE;
            } else {
                $query = Project::query()->visible()->with(['editor', 'translations'])->orderBy('name');

                if ($editor !== null) {
                    $query->whereIn('editor_id', Editor::query()->where('slug', $editor)->select('id'));
                }

                $paginator = $query->paginate(self::PER_PAGE, ['*'], 'page', $page);
                $projects = $paginator->getCollection();
                $total = $paginator->total();
                $hasMore = $paginator->hasMorePages();
            }

            $data = [
                'projects' => $projects
                    ->map(static fn (Project $project): array => ReadingPayload::project($project, $locale))
                    ->values()
                    ->all(),
                'page' => $page,
                'total' => $total,
                'has_more' => $hasMore,
            ];

            if ($total === 0) {
                $data['empty_reason'] = 'No project sheet matches. DoliNews only lists the projects its editors chose to present here: a module may exist without a sheet.';
            }

            return Response::structured(ReadingPayload::envelope($data));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Words to look for; every word must match the sheet or its editor name. Leave empty to list sheets alphabetically.'),
            'editor' => $schema->string()
                ->description('Slug of an editor, to keep its sheets only.'),
            'locale' => $schema->string()
                ->enum(ReadingPayload::locales())
                ->description('Language of the reader: summaries are given in it when the sheet has a version in it. Default: en.'),
            'page' => $schema->integer()->min(1)->description('Page number, from 1, '.self::PER_PAGE.' sheets per page.'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Dolinews\Seo\SitemapBuilder;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * The sitemap, as an index and its sections.
 *
 * Cached like the generic feeds and for the same reason: a crawler that
 * comes back every hour would otherwise walk the whole published feed
 * each time, and it is the one visitor that never gives up.
 */
class SitemapController extends Controller
{
    public function __construct(
        private readonly SitemapBuilder $sitemaps,
    ) {}

    /**
     * The index, which names the sections and nothing else.
     */
    public function index(): Response
    {
        $xml = $this->cached('sitemap:index', function (): string {
            $sections = ['pages', 'projects', 'editors'];

            for ($page = 1; $page <= $this->sitemaps->articlePageCount(); $page++) {
                $sections[] = 'articles-'.$page;
            }

            return $this->sitemaps->renderIndex(array_map(
                static fn (string $section): array => [
                    'loc' => route('sitemap.section', ['section' => $section]),
                ],
                $sections,
            ));
        });

        return $this->xml($xml);
    }

    /**
     * One section. The route constrains the name, so an unknown one
     * never reaches here.
     */
    public function section(string $section): Response
    {
        $xml = $this->cached('sitemap:'.$section, function () use ($section): string {
            $urls = match (true) {
                $section === 'pages' => $this->sitemaps->pages(),
                $section === 'projects' => $this->sitemaps->projects(),
                $section === 'editors' => $this->sitemaps->editors(),
                default => $this->sitemaps->articles(
                    (int) substr($section, strlen('articles-')),
                ),
            };

            return $this->sitemaps->renderUrlSet($urls);
        });

        return $this->xml($xml);
    }

    /**
     * @param  Closure(): string  $build
     */
    private function cached(string $key, Closure $build): string
    {
        return (string) Cache::remember(
            $key,
            (int) config('dolinews.feeds.cache_seconds', 300),
            $build,
        );
    }

    private function xml(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}

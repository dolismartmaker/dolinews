<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Seo;

use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use Carbon\Carbon;

/**
 * The map a crawler reads to find what the service holds.
 *
 * Every published version of an announcement is listed, translations
 * included: a language version is an article in its own right (SPEC
 * D14), it has its own address and its own text, and the alternates
 * declared in its page are what ties the group back together.
 *
 * The document is split rather than written in one piece. A catalogue
 * deposited at bootstrap (SPEC 5.1) is already hundreds of versions,
 * multiplied by the ten content locales; the 50 000 URL limit of the
 * format is reachable, and a map that silently drops its tail is worse
 * than no map. Articles are chunked in the order they were created, so
 * a new announcement lands in the last chunk and leaves the others
 * untouched.
 */
class SitemapBuilder
{
    public const CHUNK = 10000;

    /**
     * Static public pages (SPEC 6, 9.2, 12), in each offered language.
     *
     * Every language is listed rather than the current one: these pages
     * are fully translated, each version has its own address (SPEC 6.5),
     * and a map that named one of them would leave the nine others to be
     * found by chance.
     *
     * The report forms are left out on purpose: there is one per article
     * and per sheet, they hold no content of their own, and the way in
     * is the link carried by the page they report (SPEC 9.9). The same
     * goes for the feeds, which are not pages.
     *
     * @return list<array{loc: string, lastmod?: string}>
     */
    public function pages(): array
    {
        $names = [
            'home',
            'review.info',
            'pages.commitments',
            'pages.rules',
            'pages.data',
            'pages.legal',
            'pages.editor-guide',
            'pages.api',
        ];

        $urls = [];

        foreach ($names as $name) {
            foreach ($this->locales() as $locale) {
                $urls[] = ['loc' => route($name, ['locale' => $locale])];
            }
        }

        return $urls;
    }

    /**
     * @return list<array{loc: string, lastmod?: string}>
     */
    public function projects(): array
    {
        $urls = [];

        foreach (Project::query()->orderBy('id')->get() as $project) {
            foreach ($this->locales() as $locale) {
                $urls[] = $this->entry(
                    route('projects.show', ['locale' => $locale, 'slug' => $project->slug]),
                    $project->updated_at,
                );
            }
        }

        return $urls;
    }

    /**
     * @return list<array{loc: string, lastmod?: string}>
     */
    public function editors(): array
    {
        $urls = [];

        foreach (Editor::query()->orderBy('id')->get() as $editor) {
            foreach ($this->locales() as $locale) {
                $urls[] = $this->entry(
                    route('editors.show', ['locale' => $locale, 'slug' => $editor->slug]),
                    $editor->updated_at,
                );
            }
        }

        return $urls;
    }

    /**
     * One chunk of published articles, 1-indexed.
     *
     * @return list<array{loc: string, lastmod?: string}>
     */
    public function articles(int $page): array
    {
        return array_values(Article::query()
            ->published()
            ->orderBy('id')
            ->forPage(max(1, $page), self::CHUNK)
            ->get(['id', 'locale', 'updated_at', 'published_at'])
            ->map(fn (Article $article): array => $this->entry(
                // Under the language it is written in, never under the
                // ten interface locales: an announcement has one text,
                // and its translations are articles of their own.
                route('articles.show', [
                    'locale' => PageLocale::short($article->locale),
                    'article' => $article->getKey(),
                ]),
                $article->updated_at ?? $article->published_at,
            ))
            ->all());
    }

    /**
     * Number of article chunks, never less than one: an empty service
     * still answers a well-formed map.
     */
    public function articlePageCount(): int
    {
        $total = Article::query()->published()->count();

        return max(1, (int) ceil($total / self::CHUNK));
    }

    /**
     * @param  list<array{loc: string, lastmod?: string}>  $urls
     */
    public function renderUrlSet(array $urls): string
    {
        $body = '';

        foreach ($urls as $url) {
            $body .= '<url><loc>'.$this->escape($url['loc']).'</loc>';

            if (isset($url['lastmod'])) {
                $body .= '<lastmod>'.$this->escape($url['lastmod']).'</lastmod>';
            }

            $body .= '</url>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .$body
            .'</urlset>';
    }

    /**
     * @param  list<array{loc: string, lastmod?: string}>  $sitemaps
     */
    public function renderIndex(array $sitemaps): string
    {
        $body = '';

        foreach ($sitemaps as $sitemap) {
            $body .= '<sitemap><loc>'.$this->escape($sitemap['loc']).'</loc>';

            if (isset($sitemap['lastmod'])) {
                $body .= '<lastmod>'.$this->escape($sitemap['lastmod']).'</lastmod>';
            }

            $body .= '</sitemap>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .$body
            .'</sitemapindex>';
    }

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        return array_values((array) config('dolinews.locales', ['fr']));
    }

    /**
     * @return array{loc: string, lastmod?: string}
     */
    private function entry(string $loc, ?Carbon $lastmod): array
    {
        return $lastmod === null
            ? ['loc' => $loc]
            : ['loc' => $loc, 'lastmod' => $lastmod->toAtomString()];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

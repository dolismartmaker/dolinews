<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Dolinews\Articles\TranslationService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Markdown\ArticleMarkdown;
use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Seo\PageLocale;
use App\Domain\Dolinews\Seo\StructuredData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Public reading of one feed entry (SPEC 4.3/6).
 */
class ArticleController extends Controller
{
    public function __construct(
        private readonly ArticleMarkdown $markdown,
        private readonly TranslationService $translations,
        private readonly StructuredData $structuredData,
    ) {}

    /**
     * Show a published article: rendered body, maturity badge WITH its
     * age (SPEC 6.3), correction mention, language versions, stale
     * translation notice (SPEC 5.4).
     */
    public function show(Article $article): View|RedirectResponse
    {
        abort_unless(
            $article->status === ArticleStatus::PUBLISHED
            && $article->deleted_at === null,
            404,
        );

        /** @var User|null $reader */
        $reader = auth()->user();

        $siblings = $this->translations->publishedSiblings($article);

        // Read under a language the announcement has a version of: the
        // reader gets that version. It is the same fallback the feed
        // already applies to a list (SPEC 6.1), carried over to the one
        // address a reader is most likely to be handed by someone else.
        $elsewhere = $this->versionIn(app()->getLocale(), $siblings);

        if ($elsewhere !== null) {
            return redirect()->route('articles.show', ['article' => $elsewhere->getKey()]);
        }

        $illustration = $article->media()->first()?->url();

        return view('public.article', [
            'article' => $article,
            'bodyHtml' => $this->markdown->render($article->body),
            'siblings' => $siblings,
            'source' => $article->sourceArticle(),
            // Head of the page: the language versions declared to one
            // another, the sharing card and the machine-readable form of
            // the announcement.
            'alternates' => $this->alternates($article, $siblings),
            'sourceUrl' => $this->sourceUrl($article, $siblings),
            // An announcement nobody translated is readable under every
            // interface language, and says so: one address for one text,
            // the one written in the language of the text.
            'canonicalUrl' => $this->addressOf($article),
            'structuredData' => $this->structuredData->forArticle($article, $illustration),
            'ogType' => 'article',
            'ogLocale' => $article->locale,
            'ogImage' => $illustration,
            'stale' => $article->isStaleTranslation(),
            'correction' => $article->lastAppliedRevision(),
            'maturityAgeMonths' => $article->published_at !== null
                ? (int) round($article->published_at->diffInMonths(now()))
                : 0,
            // The way in for whoever may write a language version: the
            // editor, and the accounts it mandated (SPEC 5.6). A
            // mandated translator reads the announcement here and has
            // no other screen to start from.
            'canTranslate' => $reader !== null
                && $this->translations->canTranslate($article, $reader),
        ]);
    }

    /**
     * Language tag => address, for this article and every published
     * version of the same announcement, itself included: a page that
     * omits itself from the set declares a group it does not belong to.
     *
     * @param  array<int, Article>  $siblings
     * @return array<string, string>
     */
    private function alternates(Article $article, array $siblings): array
    {
        $alternates = [];

        foreach ([$article, ...$siblings] as $version) {
            $alternates[PageLocale::tag($version->locale)] = $this->addressOf($version);
        }

        return $alternates;
    }

    /**
     * The address of one version, under the language it is written in
     * rather than the one currently being read.
     */
    private function addressOf(Article $article): string
    {
        return route('articles.show', [
            'locale' => PageLocale::short($article->locale),
            'article' => $article->getKey(),
        ]);
    }

    /**
     * The version of this announcement written in the given interface
     * language, or null when the group has none - including when the
     * article being read is already that version.
     *
     * @param  array<int, Article>  $siblings
     */
    private function versionIn(string $locale, array $siblings): ?Article
    {
        foreach ($siblings as $version) {
            if (PageLocale::short($version->locale) === $locale) {
                return $version;
            }
        }

        return null;
    }

    /**
     * The source version of the announcement, offered to a reader whose
     * language the group does not carry. Null when the source itself is
     * not published: a translation never stands in for it (SPEC 5.1).
     *
     * @param  array<int, Article>  $siblings
     */
    private function sourceUrl(Article $article, array $siblings): ?string
    {
        foreach ([$article, ...$siblings] as $version) {
            if ($version->is_source) {
                return $this->addressOf($version);
            }
        }

        return null;
    }
}

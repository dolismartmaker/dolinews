<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Dolinews\Articles\TranslationService;
use App\Domain\Dolinews\Enums\ArticleStatus;
use App\Domain\Dolinews\Markdown\ArticleMarkdown;
use App\Domain\Dolinews\Models\Article;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;

/**
 * Public reading of one feed entry (SPEC 4.3/6).
 */
class ArticleController extends Controller
{
    public function __construct(
        private readonly ArticleMarkdown $markdown,
        private readonly TranslationService $translations,
    ) {}

    /**
     * Show a published article: rendered body, maturity badge WITH its
     * age (SPEC 6.3), correction mention, language versions, stale
     * translation notice (SPEC 5.4).
     */
    public function show(Article $article): View
    {
        abort_unless(
            $article->status === ArticleStatus::PUBLISHED
            && $article->deleted_at === null,
            404,
        );

        /** @var User|null $reader */
        $reader = auth()->user();

        return view('public.article', [
            'article' => $article,
            'bodyHtml' => $this->markdown->render($article->body),
            'siblings' => $this->translations->publishedSiblings($article),
            'source' => $article->sourceArticle(),
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
}

<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Seo;

use App\Domain\Dolinews\Models\Article;

/**
 * The address of one announcement, under the language it is written in.
 *
 * Public addresses carry a language (SPEC 6.5), and route() fills it
 * with the one being read - right for a page of the site, wrong for an
 * announcement, which has one text and therefore one address. Two
 * consequences make this worth a class of its own:
 *
 *  - a feed entry is identified by its address. Generated under the
 *    locale of whoever asked for the feed, the same announcement would
 *    take a different identity in each language, and a reader polling
 *    two of them would be told about it twice;
 *  - a link to a version that exists is a link that does not bounce.
 *    Asking for an announcement under another language answers with a
 *    redirect, which is the right behaviour for a link someone was
 *    handed, and a waste on every link the service writes itself.
 */
final class ArticleUrl
{
    public static function for(Article $article): string
    {
        return route('articles.show', [
            'locale' => PageLocale::short($article->locale),
            'article' => $article->getKey(),
        ]);
    }
}

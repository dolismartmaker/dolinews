<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Feeds;

use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Seo\ArticleUrl;

/**
 * RSS 2.0 rendering of feed slices (SPEC 6.4).
 *
 * Hand-rolled on DOMDocument: the documents are small, the format is
 * strict, and an XML library passthrough would let a stray & break the
 * whole feed for every reader at once.
 */
class RssRenderer
{
    /**
     * Render one RSS document.
     *
     * @param  array<int, Article>  $articles
     * @param  array{title: string, link: string, description: string, self_url: string, language?: string}  $channel
     */
    public function render(array $articles, array $channel): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $rss = $document->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:atom', 'http://www.w3.org/2005/Atom');
        $document->appendChild($rss);

        $channelNode = $document->createElement('channel');
        $rss->appendChild($channelNode);

        $this->appendText($document, $channelNode, 'title', $channel['title']);
        $this->appendText($document, $channelNode, 'link', $channel['link']);
        $this->appendText($document, $channelNode, 'description', $channel['description']);

        $self = $document->createElement('atom:link');
        $self->setAttribute('href', $channel['self_url']);
        $self->setAttribute('rel', 'self');
        $self->setAttribute('type', 'application/rss+xml');
        $channelNode->appendChild($self);

        // The language of the entries, which a reader aggregating ten
        // feeds has no other way to know: a feed asked in Greek and one
        // asked in French carry the same announcements under different
        // titles.
        $this->appendText(
            $document,
            $channelNode,
            'language',
            $channel['language'] ?? app()->getLocale(),
        );

        // The date of the newest entry rather than the hour of the
        // request: the document is cached, and a build stamp that moves
        // while the content does not tells a polling reader to fetch
        // again for nothing.
        $newest = $articles[0]->published_at ?? null;

        if ($newest !== null) {
            $this->appendText($document, $channelNode, 'lastBuildDate', $newest->toRfc2822String());
        }

        $this->appendText($document, $channelNode, 'generator', 'DoliNews');

        // Share-alike only binds a reuser who knows the licence: a feed
        // that omits it hands out copies with no notice attached
        // (SPEC D15).
        $this->appendText(
            $document,
            $channelNode,
            'copyright',
            (string) config('dolinews.content_license.name')
                .' - '.(string) config('dolinews.content_license.url'),
        );

        foreach ($articles as $article) {
            $this->appendItem($document, $channelNode, $article);
        }

        return (string) $document->saveXML();
    }

    /**
     * One RSS item per published article.
     */
    private function appendItem(\DOMDocument $document, \DOMElement $channel, Article $article): void
    {
        $item = $document->createElement('item');

        $this->appendText($document, $item, 'title', $article->title);
        $this->appendText(
            $document,
            $item,
            'link',
            ArticleUrl::for($article),
        );
        $this->appendText($document, $item, 'description', $article->summary);
        $this->appendText(
            $document,
            $item,
            'guid',
            ArticleUrl::for($article),
        );
        $this->appendText(
            $document,
            $item,
            'pubDate',
            ($article->published_at ?? now())->toRfc2822String(),
        );

        $category = $article->focus?->label();

        if ($category !== null) {
            $this->appendText($document, $item, 'category', $category);
        }

        $channel->appendChild($item);
    }

    /**
     * Append a text child, escaping being DOMDocument's business.
     */
    private function appendText(\DOMDocument $document, \DOMElement $parent, string $name, string $value): void
    {
        $node = $document->createElement($name);
        $node->appendChild($document->createTextNode($value));
        $parent->appendChild($node);
    }
}

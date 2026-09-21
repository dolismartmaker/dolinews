<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Markdown;

use App\Domain\Dolinews\Models\Media;
use DOMAttr;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Renders article bodies: Markdown in, whitelist-HTML out (SPEC D5).
 *
 * CommonMark never passes raw HTML through (html_input strip) and refuses
 * unsafe links. On top of that, every outgoing link gets
 * rel="nofollow ugc" (SPEC D8, without exception) and only images served
 * by this service's media disk survive: the two-step publication means
 * the body may only reference media already deposited here (SPEC 5.2).
 */
class ArticleMarkdown
{
    /**
     * HTML tags the renderer may emit; everything else is stripped.
     *
     * @var array<int, string>
     */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'em', 'code', 'pre', 'blockquote',
        'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'a', 'img', 'hr',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'del',
    ];

    /**
     * Attributes kept per tag, beyond the ones this class manages itself.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title'],
        'td' => ['align'],
        'th' => ['align'],
    ];

    /**
     * Media already looked up during the current render, keyed by their
     * path on the disk: one body shows the same screenshot twice often
     * enough to be worth not asking twice. Reset at every render so a
     * long-lived instance never serves a purged medium.
     *
     * @var array<string, Media|null>
     */
    private array $resolved = [];

    /**
     * Render a Markdown body to whitelist-HTML.
     */
    public function render(string $markdown): string
    {
        $this->resolved = [];

        $html = $this->converter()->convert($markdown)->getContent();

        return $this->sanitize($html);
    }

    /**
     * The configured CommonMark environment.
     */
    private function converter(): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'external_link' => [
                // Marks external hosts so the sanitizer can nofollow
                // every outgoing link (SPEC D8).
                'internal_hosts' => [config('app.url')],
                'open_in_new_window' => false,
                'nofollow' => '',
                'noopener' => '',
                'noreferrer' => '',
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new AutolinkExtension);
        $environment->addExtension(new ExternalLinkExtension);

        return new MarkdownConverter($environment);
    }

    /**
     * Whitelist pass over the produced HTML: allowed tags and attributes
     * only, nofollow ugc on every link, local media only for images.
     */
    private function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new \DOMDocument;

        libxml_use_internal_errors(true);

        /** @var bool $loaded */
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8"?><div id="dolinews-root">'.$html.'</div>'
        );

        libxml_clear_errors();

        if (! $loaded) {
            return '';
        }

        // getElementById needs a DTD-declared attribute: find the wrapper
        // by hand instead, it is the first div of the parsed document.
        $root = null;

        foreach ($document->getElementsByTagName('div') as $candidate) {
            if ($candidate->getAttribute('id') === 'dolinews-root') {
                $root = $candidate;

                break;
            }
        }

        if ($root === null) {
            return '';
        }

        $this->sanitizeNode($root);

        $rendered = '';

        foreach ($root->childNodes as $child) {
            $rendered .= $document->saveHTML($child);
        }

        return $rendered;
    }

    /**
     * Recursively sanitize a subtree: strip unknown tags (keeping their
     * children), drop disallowed attributes, apply the link and image
     * policies.
     */
    private function sanitizeNode(DOMNode $node): void
    {
        $children = [];

        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $this->sanitizeNode($child);

                if (! in_array(mb_strtolower($child->tagName), self::ALLOWED_TAGS, true)) {
                    // Unwrap: drop the tag, keep the sanitized children.
                    while ($child->firstChild !== null) {
                        $node->insertBefore($child->firstChild, $child);
                    }

                    $node->removeChild($child);

                    continue;
                }

                $this->sanitizeElement($child);
            }
        }
    }

    /**
     * Apply the per-tag policy: attribute whitelist, link rel, image
     * source locality.
     */
    private function sanitizeElement(DOMElement $element): void
    {
        $tag = mb_strtolower($element->tagName);
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

        /** @var list<DOMAttr> $attributes */
        $attributes = [];

        foreach ($element->attributes ?? [] as $attribute) {
            $attributes[] = $attribute;
        }

        foreach ($attributes as $attribute) {
            $name = mb_strtolower($attribute->name);

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->name);
            }
        }

        if ($tag === 'a' && $element->hasAttribute('href')) {
            // Every outgoing link, without exception (SPEC D8).
            $element->setAttribute('rel', 'nofollow ugc');
        }

        if ($tag === 'img') {
            $src = (string) $element->getAttribute('src');
            $media = $this->resolveMedium($src);

            if ($media === null) {
                // Only media deposited through this service's intake may
                // illustrate an article (SPEC 5.2/7).
                Log::warning('ArticleMarkdown: image dropped, no medium of this service answers its source', [
                    'src' => $src,
                ]);

                $element->parentNode?->removeChild($element);

                return;
            }

            // Bodies freeze the absolute URL returned at deposit time,
            // and the service may be served under another scheme or host
            // since. Serving the canonical URL of the medium found in
            // base keeps published articles illustrated across such a
            // move, and makes a hotlink impossible: whatever host the
            // body carried, the src emitted is this disk's.
            $element->setAttribute('src', $media->url());
        }
    }

    /**
     * The medium this image source designates, or null when none does.
     *
     * The match is made on the path, never on the full URL: the host and
     * the scheme of a body outlive the configuration they were written
     * under. The path of the media disk is expected to stay put; moving
     * the service under another directory is a migration that has to
     * rewrite the bodies anyway.
     */
    private function resolveMedium(string $url): ?Media
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $base = (string) parse_url(Storage::disk(Media::DISK)->url(''), PHP_URL_PATH);

        if ($base === '' || ! str_starts_with($path, $base)) {
            return null;
        }

        $relative = rawurldecode(ltrim(substr($path, strlen($base)), '/'));

        if ($relative === '') {
            return null;
        }

        if (array_key_exists($relative, $this->resolved)) {
            return $this->resolved[$relative];
        }

        /** @var Media|null $media */
        $media = Media::query()->where('path', $relative)->first();

        return $this->resolved[$relative] = $media;
    }
}

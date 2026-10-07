<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Releases;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reader of the public release feed of the Dolibarr core (SPEC 5.8).
 *
 * An Atom document and not a forge API: what is read here is a public
 * feed any mirror can serve, no token, no account, no rate plan. D4
 * forbids resting the contributor check on a forge API because the check
 * must survive the forge closing; the same reasoning applies here, and
 * the way out is already on disk - the reference clone harvested for the
 * committer index carries the same tags and the same ChangeLog. Should
 * the feed disappear, this class is what gets replaced, and nothing
 * else.
 *
 * Every failure returns an empty list after saying why: a watch that
 * cannot read its source must look like a watch that found nothing new,
 * never like a failed run that stops the scheduler.
 */
class ReleaseFeedClient
{
    /**
     * Fetch the feed and keep the stable releases it announces, newest
     * first.
     *
     * @return array<int, DolibarrRelease>
     */
    public function fetch(): array
    {
        $url = (string) config('dolinews.releases.feed_url', '');

        if ($url === '') {
            Log::notice('ReleaseFeedClient: no feed configured, nothing to watch');

            return [];
        }

        try {
            $response = Http::timeout((int) config('dolinews.releases.timeout', 20))
                ->withHeaders(['Accept' => 'application/atom+xml'])
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('ReleaseFeedClient: request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('ReleaseFeedClient: feed answered an error', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return [];
        }

        return $this->parse($response->body());
    }

    /**
     * Parse an Atom document into releases, newest first.
     *
     * @return array<int, DolibarrRelease>
     */
    public function parse(string $xml): array
    {
        if (trim($xml) === '') {
            Log::warning('ReleaseFeedClient: empty feed document');

            return [];
        }

        // No LIBXML_NOENT: entity substitution on a document fetched from
        // the network is how a feed reads local files. NONET closes the
        // other half of the same door.
        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($feed === false) {
            Log::warning('ReleaseFeedClient: feed is not parsable XML');

            return [];
        }

        $limit = (int) config('dolinews.releases.source_chars', 20000);
        $releases = [];

        foreach ($feed->entry as $entry) {
            $url = $this->attribute($entry, 'link', 'href');
            $tag = $this->tagFromUrl($url) ?? trim((string) $entry->title);
            $updated = trim((string) $entry->updated);

            if ($tag === '' || $updated === '') {
                continue;
            }

            try {
                $releasedAt = Carbon::parse($updated);
            } catch (\Throwable $e) {
                Log::info('ReleaseFeedClient: entry with an unreadable date', [
                    'tag' => $tag,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $release = DolibarrRelease::fromTag(
                $tag,
                $releasedAt,
                $url,
                $this->plainText((string) $entry->content, $limit),
            );

            if ($release !== null) {
                $releases[] = $release;
            }
        }

        usort(
            $releases,
            static fn (DolibarrRelease $a, DolibarrRelease $b): int => $b->releasedAt <=> $a->releasedAt,
        );

        return $releases;
    }

    /**
     * The tag a release address ends with, null when the address is not
     * one. Read from the address rather than from the entry title, which
     * is free text an author may set to anything.
     */
    private function tagFromUrl(string $url): ?string
    {
        if (preg_match('~/releases/tag/([^/?\#]+)$~', $url, $m) !== 1) {
            return null;
        }

        return urldecode($m[1]);
    }

    /**
     * Read an attribute of a child element, empty string when absent.
     */
    private function attribute(\SimpleXMLElement $entry, string $child, string $name): string
    {
        $attributes = $entry->{$child}->attributes();

        if ($attributes === null) {
            return '';
        }

        return trim((string) ($attributes[$name] ?? ''));
    }

    /**
     * Turn the HTML body of a release note into plain text, bounded.
     *
     * Bounded because what comes out of here is handed to a writer that
     * bills what it reads, and a major release note runs to hundreds of
     * lines. The cut is on characters and announced to the writer, which
     * then writes about what it was given instead of inventing an ending.
     */
    private function plainText(string $html, int $limit): string
    {
        // List items and paragraphs carry the structure of a changelog;
        // flattened without them, every fix runs into the next one.
        $text = (string) preg_replace('#<li[^>]*>#i', "\n- ", $html);
        $text = (string) preg_replace('#</(p|div|h[1-6]|tr)>#i', "\n", $text);
        $text = (string) preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/[ \t]+/", ' ', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text);

        if ($limit > 0 && mb_strlen($text) > $limit) {
            $text = mb_substr($text, 0, $limit)."\n[...]";
        }

        return $text;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Projects;

use App\Domain\Dolinews\Enums\LinkType;
use App\Domain\Dolinews\Models\Editor;

/**
 * Outgoing link rules (SPEC 8).
 *
 * Shorteners are refused: they mask the destination and can be
 * redirected after validation. A link whose domain differs from the
 * editor's declared domain is signalled to the moderation team.
 */
class LinkPolicy
{
    /**
     * Whether a URL may be stored on a sheet.
     *
     * @return array{ok: bool, reason: string}
     */
    public function validate(string $url): array
    {
        if (mb_strlen($url) > 2048) {
            return ['ok' => false, 'reason' => 'URL trop longue.'];
        }

        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($host) || $host === '') {
            return ['ok' => false, 'reason' => 'URL illisible.'];
        }

        if (! in_array($scheme, ['http', 'https'], true)) {
            return ['ok' => false, 'reason' => 'Seuls les schémas http et https sont acceptés.'];
        }

        if ($this->isShortener($host)) {
            return ['ok' => false, 'reason' => 'Les raccourcisseurs d\'URL sont refusés : ils masquent la destination.'];
        }

        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Whether the host is a known URL shortener.
     */
    public function isShortener(string $host): bool
    {
        $host = mb_strtolower(trim($host));

        return in_array($host, (array) config('dolinews.links.shortener_domains', []), true);
    }

    /**
     * Extract the external identity of a typed link when possible: the
     * Dolistore sheet id from the URL (SPEC 4.2). The (type, external_id)
     * uniqueness is what detects claim conflicts (SPEC 9.5).
     */
    public function extractExternalId(LinkType $type, string $url): ?string
    {
        if ($type !== LinkType::DOLISTORE) {
            return null;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');

        // Dolistore product URLs end with /<id>-<slug>.html
        if (preg_match('#/(\d+)-[^/]+\.html$#', $path, $matches) === 1) {
            return $matches[1];
        }

        // Category or bare-id forms.
        if (preg_match('#/(\d+)(?:\.html)?$#', $path, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * The host of a URL as the domain comparison reads it: lowercased,
     * without its leading "www.". Null when the URL has no host.
     *
     * Stored on every link and on every editor at save time
     * (project_links.host, editors.website_host), so the back-office can
     * count and sort the mismatches in SQL.
     */
    public static function hostOf(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = mb_strtolower($host);

        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    /**
     * Whether a normalized host is the domain or one of its subdomains.
     */
    public static function isWithin(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    /**
     * The domains a host may be accepted at, from the host itself up to
     * its last two labels: demo.dolitest.fr gives demo.dolitest.fr and
     * dolitest.fr. Where a registrable domain ends cannot be told without
     * the public suffix list, so the super admin picks the level.
     *
     * @return list<string>
     */
    public static function domainLevels(string $host): array
    {
        $labels = explode('.', $host);
        $levels = [];

        for ($i = 0; count($labels) - $i >= 2; $i++) {
            $levels[] = implode('.', array_slice($labels, $i));
        }

        return $levels;
    }

    /**
     * Whether a link's domain matches the editor's declared domain
     * (SPEC 8). A mismatch is not a refusal, it is a signal to the
     * moderation team. Dolistore and forge links are never compared:
     * they live off the editor's domain by nature. Nor are the links on a
     * domain the super admin accepted for every sheet.
     *
     * @param  list<string>  $acceptedDomains  normalized domains accepted on any sheet
     */
    public function matchesEditorDomain(string $url, Editor $editor, LinkType $type = LinkType::OTHER, array $acceptedDomains = []): bool
    {
        if (! $type->expectsEditorDomain()) {
            return true;
        }

        $declared = self::hostOf($editor->website);

        if ($declared === null) {
            // No declared domain: nothing to compare against, no signal.
            return true;
        }

        $host = self::hostOf($url);

        if ($host === null) {
            return false;
        }

        if (self::isWithin($host, $declared)) {
            return true;
        }

        foreach ($acceptedDomains as $domain) {
            if (self::isWithin($host, $domain)) {
                return true;
            }
        }

        return false;
    }
}

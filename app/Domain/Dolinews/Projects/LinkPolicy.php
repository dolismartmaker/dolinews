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
     * Whether a link's domain matches the editor's declared domain
     * (SPEC 8). A mismatch is not a refusal, it is a signal to the
     * moderation team.
     */
    public function matchesEditorDomain(string $url, Editor $editor): bool
    {
        $declared = $editor->website !== null
            ? parse_url($editor->website, PHP_URL_HOST)
            : null;

        if (! is_string($declared) || $declared === '') {
            // No declared domain: nothing to compare against, no signal.
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $declared = mb_strtolower(preg_replace('/^www\./', '', $declared) ?? $declared);
        $host = mb_strtolower(preg_replace('/^www\./', '', $host) ?? $host);

        return $host === $declared || str_ends_with($host, '.'.$declared);
    }
}

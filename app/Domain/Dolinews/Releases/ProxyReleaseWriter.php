<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Releases;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The deployment's own writing endpoint, reached over HTTP (SPEC 5.8).
 *
 * Contract, which any deployment may serve with something else:
 *
 *   POST {endpoint}/write
 *   Authorization: Bearer <token>
 *   {"version": "21.0.1", "released_at": "2026-03-03", "url": "...",
 *    "locale": "fr_FR", "instructions": "...", "changelog": "..."}
 *   -> {"success": true, "article": {"title": "...", "summary": "...",
 *       "body": "..."}}
 *
 * The editorial constraints travel with the request rather than sitting
 * in the endpoint's configuration: they belong to the service, which
 * answers for what it publishes (SPEC 9.7), and an endpoint swapped for
 * another must carry them unchanged. The first of them is the one that
 * matters legally - reformulate, never reproduce: the release note
 * belongs to its authors and is published under the licence of their
 * project, not under the one this service publishes under (SPEC D15).
 *
 * The answer is prose and prose only. Anything the review queue or the
 * subscription mails hang on is derived from the release itself, so a
 * wrong answer here can waste a reviewer's time and nothing else.
 */
class ProxyReleaseWriter implements ReleaseWriter
{
    /** Bounds on the answer, in characters. */
    private const MAX_TITLE = 200;

    private const MAX_SUMMARY = 600;

    private const MAX_BODY = 20000;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $token,
    ) {}

    public function isAvailable(): bool
    {
        return $this->endpoint !== '' && $this->token !== '';
    }

    public function write(DolibarrRelease $release, string $locale): ?WrittenRelease
    {
        if (! $this->isAvailable()) {
            return null;
        }

        try {
            $response = Http::timeout((int) config('dolinews.releases.writer.timeout', 60))
                ->withToken($this->token)
                ->acceptJson()
                ->post(rtrim($this->endpoint, '/').'/write', [
                    'version' => $release->version(),
                    'released_at' => $release->releasedAt->toDateString(),
                    'url' => $release->url,
                    'locale' => $locale,
                    'instructions' => self::instructions($locale),
                    'changelog' => $release->changelog,
                ]);
        } catch (\Throwable $e) {
            Log::warning('ProxyReleaseWriter: request failed', [
                'version' => $release->version(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful() || $response->json('success') !== true) {
            Log::warning('ProxyReleaseWriter: endpoint answered an error', [
                'version' => $release->version(),
                'status' => $response->status(),
            ]);

            return null;
        }

        $title = $this->field($response->json('article.title'), self::MAX_TITLE);
        $summary = $this->field($response->json('article.summary'), self::MAX_SUMMARY);
        $body = $this->field($response->json('article.body'), self::MAX_BODY);

        if ($title === null || $summary === null || $body === null) {
            Log::warning('ProxyReleaseWriter: unusable answer, falling back', [
                'version' => $release->version(),
            ]);

            return null;
        }

        return new WrittenRelease($title, $summary, $body);
    }

    /**
     * The editorial constraints handed to the endpoint.
     *
     * Public so the test suite reads them here instead of holding a
     * second copy that drifts: the one on reproduction is a legal bound,
     * not a style preference.
     */
    public static function instructions(string $locale): string
    {
        return implode("\n", [
            'Rédiger une annonce de parution pour le fil DoliNews, en '.$locale.'.',
            'Lectorat : des intégrateurs et des administrateurs qui exploitent Dolibarr en production.',
            'Ne jamais reproduire une ligne du journal fourni : reformuler, regrouper par thème, citer la source par son lien.',
            'Ne rien affirmer qui ne figure pas dans le journal fourni ; ne pas combler un journal tronqué.',
            'Un titre court, un résumé d\'une phrase, un corps en Markdown de 150 à 400 mots.',
            'Markdown seul, sans HTML, sans titre de niveau 1, sans formule promotionnelle ni conclusion d\'usage.',
            'Mettre en avant ce qui décide d\'une mise à jour : correctifs de sécurité, régressions corrigées, changements de comportement.',
        ]);
    }

    /**
     * A non-empty string field within its bound, null otherwise.
     */
    private function field(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $max) {
            return null;
        }

        return $value;
    }
}

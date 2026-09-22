<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Translation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The service's own translation endpoint, reached over HTTP.
 *
 * Contract, which any deployment may serve with something else:
 *
 *   POST {endpoint}/translate
 *   Authorization: Bearer <token>
 *   {"text": ["...", "..."], "source_lang": "FR", "target_lang": "ES"}
 *   -> {"success": true, "translations": ["...", "..."], "errors": null}
 *
 * Two refusals are not failures and must not be reported as such: 402
 * with `no_active_subscription` or `allowance_exhausted` says the
 * account has nothing left to spend, which the editor's screen states
 * plainly rather than showing a breakage.
 *
 * The endpoint inserts <br /> before every line break. The body of an
 * announcement is Markdown and never carries free HTML (D5), so they are
 * removed on arrival. Undoing on this side what the other side just did
 * is not elegant, but the endpoint answers clients that may rely on it.
 */
class ProxyTranslationEngine implements TranslationEngine
{
    use TranslatesLocales;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $token,
    ) {}

    public function isAvailable(): bool
    {
        return $this->endpoint !== '' && $this->token !== '';
    }

    public function translateBatch(array $texts, string $sourceLocale, string $targetLocale): ?array
    {
        if (! $this->isAvailable() || $texts === []) {
            return null;
        }

        try {
            $response = Http::timeout((int) config('dolinews.translation.timeout', 20))
                ->withToken($this->token)
                ->acceptJson()
                ->post(rtrim($this->endpoint, '/').'/translate', [
                    'text' => array_values($texts),
                    'source_lang' => $this->engineLanguage($sourceLocale),
                    'target_lang' => $this->engineLanguage($targetLocale),
                ]);
        } catch (\Throwable $e) {
            Log::warning('ProxyTranslationEngine: request failed', [
                'target' => $targetLocale,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->status() === 402) {
            // Said with the reason: "settle" and "wait for next period"
            // are not the same message to an editor.
            Log::notice('ProxyTranslationEngine: refused on the account state', [
                'reason' => (string) $response->json('error'),
            ]);

            return null;
        }

        if (! $response->successful() || $response->json('success') !== true) {
            Log::warning('ProxyTranslationEngine: endpoint answered an error', [
                'target' => $targetLocale,
                'status' => $response->status(),
            ]);

            return null;
        }

        $translations = $response->json('translations');

        if (! is_array($translations) || count($translations) !== count($texts)) {
            Log::warning('ProxyTranslationEngine: answer does not match the batch', [
                'sent' => count($texts),
                'received' => is_array($translations) ? count($translations) : 0,
            ]);

            return null;
        }

        $clean = [];

        foreach (array_values($translations) as $text) {
            if (! is_string($text)) {
                return null;
            }

            $clean[] = $this->stripHtmlLineBreaks($text);
        }

        return $clean;
    }

    public function supportedLocales(): array
    {
        return $this->configuredLocales();
    }

    /**
     * Remove the <br /> the endpoint inserts, keeping the line breaks
     * themselves, which carry the Markdown.
     */
    private function stripHtmlLineBreaks(string $text): string
    {
        return (string) preg_replace('#<br\s*/?>#i', '', $text);
    }
}

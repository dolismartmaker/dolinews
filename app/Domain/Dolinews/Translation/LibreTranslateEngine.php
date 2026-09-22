<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Translation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A LibreTranslate-compatible engine, reached over HTTP.
 *
 * Chosen as the reference implementation because it can be self-hosted:
 * the service keeps the ability to run its own instance rather than
 * depending on a third party it does not control (SPEC 5.7). Any other
 * engine implements the same interface.
 *
 * The API takes a two-letter language code, while the service works in
 * content locales (es_ES): the mapping is a substring, which holds for
 * the ten locales the service publishes in, none of which is a regional
 * variant of another.
 */
class LibreTranslateEngine implements TranslationEngine
{
    public function isAvailable(): bool
    {
        return $this->endpoint() !== '';
    }

    public function translate(string $text, string $sourceLocale, string $targetLocale): ?string
    {
        if (! $this->isAvailable() || trim($text) === '') {
            return null;
        }

        try {
            $response = Http::timeout((int) config('dolinews.translation.timeout', 20))
                ->asJson()
                ->post($this->endpoint().'/translate', array_filter([
                    'q' => $text,
                    'source' => substr($sourceLocale, 0, 2),
                    'target' => substr($targetLocale, 0, 2),
                    // The body is Markdown and has to come back as
                    // Markdown; the title and the summary are plain and
                    // survive this format either way.
                    'format' => 'text',
                    'api_key' => $this->apiKey() !== '' ? $this->apiKey() : null,
                ], static fn ($value): bool => $value !== null));
        } catch (\Throwable $e) {
            Log::warning('LibreTranslateEngine: request failed', [
                'target' => $targetLocale,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('LibreTranslateEngine: engine refused the request', [
                'target' => $targetLocale,
                'status' => $response->status(),
            ]);

            return null;
        }

        $translated = $response->json('translatedText');

        if (! is_string($translated) || trim($translated) === '') {
            Log::warning('LibreTranslateEngine: empty translation returned', [
                'target' => $targetLocale,
            ]);

            return null;
        }

        return $translated;
    }

    public function supportedLocales(): array
    {
        $configured = (array) config('dolinews.translation.locales', []);

        return array_values(array_filter(
            array_map(static fn (mixed $locale): string => is_string($locale) ? trim($locale) : '', $configured),
            static fn (string $locale): bool => $locale !== '',
        ));
    }

    /**
     * The engine base URL, empty when none is configured.
     */
    private function endpoint(): string
    {
        return rtrim((string) config('dolinews.translation.endpoint', ''), '/');
    }

    private function apiKey(): string
    {
        return (string) config('dolinews.translation.api_key', '');
    }
}

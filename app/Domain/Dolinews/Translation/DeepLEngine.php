<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Translation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DeepL reached directly, with an editor's own key (SPEC 5.7).
 *
 * The way out for an editor whose volume exceeds what the service
 * offers: it holds an account with a third party everybody knows, pays
 * that third party, and owes DoliNews nothing. The service sells
 * nothing, which is what the public commitments require (SPEC 12).
 *
 * Same engine as the shared route, so the quality of what the service
 * publishes stays the same whichever way an announcement went out.
 *
 * Two answers are states of the account, not failures: 456 when the
 * characters are spent, 403 when the key is refused. The editor's screen
 * says which, since the remedies differ.
 */
class DeepLEngine implements TranslationEngine
{
    use TranslatesLocales;

    private const FREE_ENDPOINT = 'https://api-free.deepl.com/v2/translate';

    private const PRO_ENDPOINT = 'https://api.deepl.com/v2/translate';

    public function __construct(
        private readonly string $key,
    ) {}

    public function isAvailable(): bool
    {
        return trim($this->key) !== '';
    }

    public function translateBatch(array $texts, string $sourceLocale, string $targetLocale): ?array
    {
        if (! $this->isAvailable() || $texts === []) {
            return null;
        }

        try {
            $response = Http::timeout((int) config('dolinews.translation.timeout', 20))
                ->withHeaders(['Authorization' => 'DeepL-Auth-Key '.$this->key])
                ->acceptJson()
                ->post($this->endpoint(), [
                    'text' => array_values($texts),
                    'source_lang' => $this->engineLanguage($sourceLocale),
                    'target_lang' => $this->engineLanguage($targetLocale),
                ]);
        } catch (\Throwable $e) {
            Log::warning('DeepLEngine: request failed', [
                'target' => $targetLocale,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->status() === 456) {
            Log::notice('DeepLEngine: the editor key has no characters left');

            return null;
        }

        if ($response->status() === 403) {
            Log::notice('DeepLEngine: the editor key was refused');

            return null;
        }

        if (! $response->successful()) {
            Log::warning('DeepLEngine: answered an error status', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $translations = $response->json('translations');

        if (! is_array($translations) || count($translations) !== count($texts)) {
            Log::warning('DeepLEngine: answer does not match the batch', [
                'sent' => count($texts),
                'received' => is_array($translations) ? count($translations) : 0,
            ]);

            return null;
        }

        $result = [];

        foreach ($translations as $entry) {
            $text = is_array($entry) ? ($entry['text'] ?? null) : null;

            if (! is_string($text)) {
                Log::warning('DeepLEngine: a translation came back without its text');

                return null;
            }

            $result[] = $text;
        }

        return $result;
    }

    public function supportedLocales(): array
    {
        return $this->configuredLocales();
    }

    /**
     * Free keys end in :fx and answer on another host; sending them to
     * the paid host earns a 403 that reads as a wrong key.
     */
    private function endpoint(): string
    {
        return str_ends_with(trim($this->key), ':fx')
            ? self::FREE_ENDPOINT
            : self::PRO_ENDPOINT;
    }
}

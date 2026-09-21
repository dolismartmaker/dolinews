<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Remote report of a honeypot hit to a Fail2Band instance, when the
 * deployment sits behind a frontend (LARAVEL_HONEYPOT.md).
 *
 * Queued, never inline: the scanner sets the pace, and a synchronous
 * call to a third party on every probe would hand it one PHP process
 * per request.
 */
class ReportHoneypotHit implements ShouldQueue
{
    use Queueable;

    // Un report qui arrive dix minutes plus tard signale une adresse que la
    // prison locale a deja bannie.
    public int $tries = 1;

    public function __construct(
        public readonly string $ip,
        public readonly string $level,
        public readonly string $reason,
    ) {}

    public function handle(): void
    {
        $url = (string) config('honeypot.fail2band.url');
        $key = (string) config('honeypot.fail2band.api_key');

        if ($url === '' || $key === '') {
            Log::warning('Honeypot report skipped: Fail2Band is enabled but the URL or the API key is missing.', [
                'ip' => $this->ip,
            ]);

            return;
        }

        try {
            $response = Http::timeout((int) config('honeypot.fail2band.timeout', 5))
                ->withToken($key)
                ->post(rtrim($url, '/').'/api/v1/report', [
                    'ip' => $this->ip,
                    'source' => 'app',
                    'jail_name' => (string) config('honeypot.fail2band.jail_name', 'laravel-honeypot'),
                    'reason' => 'Honeypot: '.$this->reason,
                    'metadata' => [
                        'application' => (string) config('app.name'),
                        'level' => $this->level,
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Honeypot report refused by Fail2Band.', [
                    'ip' => $this->ip,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Honeypot report to Fail2Band failed.', ['ip' => $this->ip, 'error' => $e->getMessage()]);
        }
    }
}

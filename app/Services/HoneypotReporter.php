<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ReportHoneypotHit;
use App\Support\HoneypotMatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Honeypot effects: the log line, the address guard, the remote report
 * (LARAVEL_HONEYPOT.md).
 */
class HoneypotReporter
{
    public const MARKER = 'HONEYPOT';

    private const MAX_FIELD_LENGTH = 200;

    private const REPORT_TTL_SECONDS = 3600;

    /**
     * @param  array{level: string, reason: string}  $hit
     */
    public function report(Request $request, array $hit): void
    {
        try {
            $ip = (string) $request->ip();
            $level = $this->effectiveLevel($request, $ip, $hit['level']);

            $line = sprintf(
                '%s level=%s ip=%s method=%s path="%s" host="%s" ua="%s" reason=%s',
                self::MARKER,
                $level,
                $ip !== '' ? $ip : '-',
                $this->field($request->method()),
                // Le chemin seul, jamais la chaine de requete : une URL
                // signee y porterait sa signature, et ce fichier est lu par
                // fail2ban, par l'hebergeur et par la collecte de journaux.
                $this->field('/'.ltrim($request->path(), '/')),
                $this->field((string) $request->getHost()),
                $this->field((string) $request->userAgent()),
                $this->field($hit['reason'])
            );

            Log::channel('honeypot')->warning($line);

            if ($level !== HoneypotMatcher::LEVEL_OBSERVED) {
                $this->forward($ip, $hit);
            }
        } catch (\Throwable $e) {
            // Jamais de relance : l'appelant est un middleware place devant
            // chaque requete, et un canal casse ne doit pas transformer un
            // 404 en 500.
            Log::warning('Honeypot hit could not be recorded.', ['error' => $e->getMessage()]);
        }
    }

    private function effectiveLevel(Request $request, string $ip, string $level): string
    {
        // Une requete portant notre cookie de session vient d'un navigateur
        // qui a deja parle a l'application. Ce n'est pas une protection contre
        // un attaquant -- forger un nom de cookie est trivial -- et ce n'est
        // pas son role : elle protege de NOS erreurs de liste.
        if ($request->hasCookie((string) config('session.cookie'))) {
            Log::warning('Honeypot hit from a request carrying a session cookie: logged, not reported.', [
                'ip' => $ip !== '' ? $ip : null,
                'path' => '/'.ltrim($request->path(), '/'),
            ]);

            return HoneypotMatcher::LEVEL_OBSERVED;
        }

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            Log::warning('Honeypot hit with no usable client address.', ['ip' => $ip ?: null]);

            return HoneypotMatcher::LEVEL_OBSERVED;
        }

        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;

        if (! $isPublic) {
            Log::warning('Honeypot hit resolved to a private address: the request most likely came through a proxy whose address is not declared in TRUSTED_PROXIES.', [
                'ip' => $ip,
            ]);

            return HoneypotMatcher::LEVEL_OBSERVED;
        }

        if ($this->matchesList($ip, (array) config('honeypot.whitelist', []))) {
            Log::info('Honeypot hit from a whitelisted address.', ['ip' => $ip]);

            return HoneypotMatcher::LEVEL_OBSERVED;
        }

        if ($this->isTrustedProxy($ip)) {
            Log::warning('Honeypot hit whose client address is a declared frontend proxy: the proxy is most likely not forwarding X-Forwarded-For.', ['ip' => $ip]);

            return HoneypotMatcher::LEVEL_OBSERVED;
        }

        return $level;
    }

    private function isTrustedProxy(string $ip): bool
    {
        // La cle sur laquelle TrustProxies se rabat lui-meme, donc la garde et
        // le middleware ne peuvent pas diverger sur qui est le frontal.
        $proxies = config('trustedproxy.proxies');

        if ($proxies === null || $proxies === '' || $proxies === []) {
            return false;
        }

        $list = is_array($proxies) ? $proxies : array_map('trim', explode(',', (string) $proxies));

        foreach ($list as $entry) {
            // Un joker signifie que l'adresse vient des en-tetes transmis :
            // celle qu'on tient est celle du client, rien a comparer.
            if (str_contains((string) $entry, '*')) {
                return false;
            }
        }

        return $this->matchesList($ip, $list);
    }

    /**
     * @param  array<int, mixed>  $list
     */
    private function matchesList(string $ip, array $list): bool
    {
        $ranges = array_values(array_filter(array_map(
            static fn ($entry): string => trim((string) $entry),
            $list
        )));

        return $ranges !== [] && IpUtils::checkIp($ip, $ranges);
    }

    /**
     * @param  array{level: string, reason: string}  $hit
     */
    private function forward(string $ip, array $hit): void
    {
        if (! (bool) config('honeypot.fail2band.enabled', false)) {
            return;
        }

        // Un scan est un millier de sondes, la decision est unique.
        if (! Cache::add('honeypot:reported:'.$ip, true, self::REPORT_TTL_SECONDS)) {
            return;
        }

        try {
            ReportHoneypotHit::dispatch($ip, $hit['level'], $hit['reason']);
        } catch (\Throwable $e) {
            Log::warning('Honeypot report could not be queued.', ['ip' => $ip, 'error' => $e->getMessage()]);
        }
    }

    private function field(string $value): string
    {
        // Pas de modificateur /u : l'agent utilisateur d'une sonde est
        // regulierement du binaire, et un motif UTF-8 sur une entree invalide
        // rend null, ce qui viderait le champ qu'on cherche a diagnostiquer.
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $value);

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = (string) preg_replace('/[^\x20-\x7E]/', '', $value);
        }

        // Neutralise le marqueur dans une valeur venue du client : sans
        // cela, un scanner fait bannir l'adresse de son choix.
        $value = str_ireplace(self::MARKER, 'HONEY_POT', $value);

        $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        if ($value === '') {
            return '-';
        }

        return mb_strlen($value) > self::MAX_FIELD_LENGTH
            ? mb_substr($value, 0, self::MAX_FIELD_LENGTH).'...'
            : $value;
    }
}

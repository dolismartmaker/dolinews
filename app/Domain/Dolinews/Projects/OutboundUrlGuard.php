<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Projects;

/**
 * Guard for the only server-side fetch of the service: the periodic
 * check of the links a contributor wrote on a project sheet (SPEC 8).
 *
 * Without it the check is a request forger. `http://169.254.169.254/`,
 * `http://127.0.0.1:9000/` or an internal name would be contacted on the
 * service's behalf, and the stored result (is_broken) reports the answer
 * back to whoever posted the link.
 *
 * Refusing a literal private address is not enough: a name resolves, and
 * it can resolve to a private address, differently on every lookup. So
 * the host is resolved here, every resulting address is vetted, and the
 * caller is handed the address to pin on the connection - which closes
 * the window between the check and the call.
 */
class OutboundUrlGuard
{
    /** Redirect hops a probe may follow, each one re-vetted. */
    public const MAX_HOPS = 3;

    /**
     * Address ranges that must never be contacted. Loopback, private and
     * link-local are the obvious ones; the cloud metadata endpoint lives
     * in link-local. Carrier-grade NAT, benchmarking, documentation and
     * multicast ranges are here too: none of them ever hosts a public
     * project page, so refusing them costs nothing.
     *
     * @var list<string>
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /**
     * IPv6 counterparts. 2002::/16 and 64:ff9b::/96 embed an IPv4
     * address: reaching a private v4 host through a 6to4 or NAT64 prefix
     * is the same request, written differently.
     *
     * @var list<string>
     */
    private const BLOCKED_V6 = [
        '::/128',
        '::1/128',
        '64:ff9b::/96',
        '100::/64',
        '2001:db8::/32',
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    /**
     * Vet a URL and resolve it to one address that may be contacted.
     *
     * Every address the name resolves to is checked, not just the first:
     * a name answering one public and one private address would let the
     * private one through on the next lookup.
     *
     * @return array{ok: bool, reason: string, host: string, ip: string, port: int}
     */
    public function resolve(string $url): array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (! is_string($host) || $host === '') {
            return $this->refuse('URL illisible.');
        }

        if (! in_array($scheme, ['http', 'https'], true)) {
            return $this->refuse('Seuls les schémas http et https sont sondés.');
        }

        $port = is_int($port) ? $port : ($scheme === 'https' ? 443 : 80);

        // A project sheet links a web page. Any other port would turn the
        // check into a port scanner run from our address.
        if (! in_array($port, [80, 443], true)) {
            return $this->refuse('Seuls les ports 80 et 443 sont sondés.');
        }

        $host = trim($host, '[]');

        $addresses = $this->addressesOf($host);

        if ($addresses === []) {
            return $this->refuse('Nom d\'hôte non résolu.');
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                return $this->refuse('Adresse non publique refusée : '.$address);
            }
        }

        return [
            'ok' => true,
            'reason' => '',
            'host' => $host,
            'ip' => $addresses[0],
            'port' => $port,
        ];
    }

    /**
     * Whether an address sits outside every range listed above.
     */
    public function isPublicAddress(string $address): bool
    {
        // An IPv4-mapped IPv6 address (::ffff:127.0.0.1) carries a v4
        // address in a v6 shape: unwrap it, or the v4 ranges never match.
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $address, $matches) === 1) {
            $address = $matches[1];
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return ! $this->matchesAny($address, self::BLOCKED_V4);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            if ($this->matchesAny($address, self::BLOCKED_V6)) {
                return false;
            }

            // A 6to4 or NAT64 prefix was refused above, but any other
            // form embedding a v4 address must be judged on that address.
            $embedded = $this->embeddedV4($address);

            return $embedded === null || ! $this->matchesAny($embedded, self::BLOCKED_V4);
        }

        return false;
    }

    /**
     * The addresses a host answers, or the literal itself when the host
     * is already an address.
     *
     * @return list<string>
     */
    private function addressesOf(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        $addresses = [];

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $addresses[] = $record['ip'];
                }

                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        if ($addresses === []) {
            // Resolvers that answer nothing to dns_get_record still
            // answer the system resolver (hosts file, for one).
            $fallback = @gethostbynamel($host);

            if (is_array($fallback)) {
                $addresses = array_values(array_filter($fallback, 'is_string'));
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * The IPv4 address embedded in the last 32 bits of an IPv6 address,
     * when the first 96 bits are not a plain global prefix.
     */
    private function embeddedV4(string $address): ?string
    {
        $packed = @inet_pton($address);

        if (! is_string($packed) || strlen($packed) !== 16) {
            return null;
        }

        // Only well-known translation prefixes embed a routable v4
        // address; the refusal list above already names them, so this is
        // the belt to that braces: any address whose first 64 bits are
        // zero is read as a translation form.
        if (substr($packed, 0, 8) !== str_repeat("\0", 8)) {
            return null;
        }

        $tail = substr($packed, 12, 4);

        return inet_ntop($tail) ?: null;
    }

    /**
     * @param  list<string>  $ranges
     */
    private function matchesAny(string $address, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($this->inRange($address, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an address falls inside a CIDR range.
     */
    private function inRange(string $address, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range, 2);

        $addressBin = @inet_pton($address);
        $subnetBin = @inet_pton($subnet);

        if (! is_string($addressBin) || ! is_string($subnetBin)) {
            return false;
        }

        if (strlen($addressBin) !== strlen($subnetBin)) {
            return false;
        }

        $prefix = (int) $bits;
        $wholeBytes = intdiv($prefix, 8);
        $spareBits = $prefix % 8;

        if ($wholeBytes > 0 && substr($addressBin, 0, $wholeBytes) !== substr($subnetBin, 0, $wholeBytes)) {
            return false;
        }

        if ($spareBits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $spareBits)) & 0xFF);

        return ($addressBin[$wholeBytes] & $mask) === ($subnetBin[$wholeBytes] & $mask);
    }

    /**
     * @return array{ok: bool, reason: string, host: string, ip: string, port: int}
     */
    private function refuse(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'host' => '', 'ip' => '', 'port' => 0];
    }
}

<?php

namespace App\Stash\Fetch;

/**
 * Decides whether an IP address is a public, routable destination.
 * Blocks loopback, private, link-local (incl. cloud metadata), CGNAT,
 * multicast, documentation, benchmarking and reserved ranges for IPv4 and
 * IPv6. IPv4 embedded in IPv6 (mapped/translated, NAT64) is validated as
 * IPv4; tunnelling prefixes (6to4, Teredo) are blocked outright.
 */
final class IpGuard
{
    /** @var list<string> */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '255.255.255.255/32',
    ];

    /** @var list<string> */
    private const BLOCKED_V6 = [
        '::/128', '::1/128', '::/96', '100::/64', '2001::/23', '2001:db8::/32', '2002::/16', 'fc00::/7',
        'fe80::/10', 'fec0::/10', 'ff00::/8', '3fff::/20', '5f00::/16',
    ];

    public static function isPublic(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 4) {
            return ! self::matchesAny($binary, self::BLOCKED_V4);
        }

        // IPv6 with an embedded IPv4 address: validate the IPv4 part.
        $embedded = self::embeddedIpv4($binary);
        if ($embedded !== null) {
            return self::isPublic($embedded);
        }

        return ! self::matchesAny($binary, self::BLOCKED_V6);
    }

    private static function embeddedIpv4(string $binary): ?string
    {
        $hex = bin2hex($binary);

        // ::ffff:a.b.c.d (IPv4-mapped) and ::ffff:0:a.b.c.d (IPv4-translated)
        if (str_starts_with($hex, '00000000000000000000ffff') || str_starts_with($hex, '0000000000000000ffff0000')) {
            return inet_ntop(substr($binary, 12, 4)) ?: null;
        }

        // 64:ff9b::/96 and 64:ff9b:1::/48 NAT64
        if (str_starts_with($hex, '0064ff9b0000000000000000') || str_starts_with($hex, '0064ff9b0001')) {
            return inet_ntop(substr($binary, 12, 4)) ?: null;
        }

        // 6to4 (2002::/16) and Teredo (2001::/32) are blocked outright via BLOCKED_V6.

        return null;
    }

    /** @param list<string> $cidrs */
    private static function matchesAny(string $binary, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            [$subnet, $bits] = explode('/', $cidr);
            $subnetBinary = inet_pton($subnet);
            if ($subnetBinary === false || strlen($subnetBinary) !== strlen($binary)) {
                continue;
            }
            if (self::inSubnet($binary, $subnetBinary, (int) $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function inSubnet(string $ip, string $subnet, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr($ip, 0, $bytes) !== substr($subnet, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ip[$bytes]) & $mask) === (ord($subnet[$bytes]) & $mask);
    }
}

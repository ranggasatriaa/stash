<?php

namespace App\Stash\Support;

/**
 * Conservative URL normalization used for duplicate warnings.
 *
 * - lower-cases scheme and host, strips default ports and fragments
 * - removes well-known tracking parameters only (utm_*, fbclid, gclid, ...)
 * - keeps every other query parameter in its original order
 * - the comparison hash ignores the scheme so http/https variants match
 */
final class UrlNormalizer
{
    /** Exact tracking parameter names (lower-case). */
    private const TRACKING_PARAMS = [
        'fbclid', 'gclid', 'gclsrc', 'dclid', 'msclkid', 'yclid', 'igshid', 'mc_cid', 'mc_eid',
        '_hsenc', '_hsmi', 'mkt_tok', 'twclid', 'ttclid', 'li_fat_id', 'wbraid', 'gbraid',
        'oly_anon_id', 'oly_enc_id', 'vero_id', '_ga', '_gl', 'si',
    ];

    /** Tracking parameter prefixes (lower-case). */
    private const TRACKING_PREFIXES = ['utm_', 'pk_', 'mtm_'];

    /**
     * Validate that the value is an absolute http(s) URL without credentials.
     */
    public static function isValidHttpUrl(string $url): bool
    {
        if (strlen($url) > 4096 || preg_match('/[\x00-\x1F\x7F\s]/', $url)) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return $parts['host'] !== '';
    }

    /**
     * @return array{url: string, normalized: string, hash: string, host: string}
     */
    public static function normalize(string $url): array
    {
        $url = trim($url);
        $parts = parse_url($url) ?: [];

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $host)) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                $host = $ascii;
            }
        }

        $port = $parts['port'] ?? null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $path = $parts['path'] ?? '';
        if ($path === '') {
            $path = '/';
        }

        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $kept = [];
            foreach (explode('&', $parts['query']) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $name = strtolower(urldecode(explode('=', $pair, 2)[0]));
                if (self::isTrackingParam($name)) {
                    continue;
                }
                $kept[] = $pair;
            }
            $query = $kept ? '?'.implode('&', $kept) : '';
        }

        $authority = $host.($port ? ':'.$port : '');
        $normalized = $scheme.'://'.$authority.$path.$query;
        $comparable = '//'.$authority.$path.$query;

        return [
            'url' => $url,
            'normalized' => $normalized,
            'hash' => hash('sha256', $comparable),
            'host' => $host,
        ];
    }

    public static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /** Provisional title derived from the host when no title was provided. */
    public static function provisionalTitle(string $url): string
    {
        $host = self::host($url) ?? 'Untitled';

        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    private static function isTrackingParam(string $name): bool
    {
        if (in_array($name, self::TRACKING_PARAMS, true)) {
            return true;
        }

        foreach (self::TRACKING_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}

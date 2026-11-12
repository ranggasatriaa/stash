<?php

namespace App\Stash\Fetch;

use App\Stash\Support\UrlNormalizer;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * The single hardened HTTP client used for metadata, preview images and
 * article extraction.
 *
 * - http/https only, no credentials, allow-listed ports
 * - every hostname is resolved and *all* addresses must be public; the
 *   connection is pinned to a validated address with CURLOPT_RESOLVE so a
 *   second DNS answer cannot rebind it to an internal host
 * - redirects are followed manually and each hop is revalidated
 * - TLS verification on, environment proxies ignored, no cookies or
 *   credentials forwarded, byte/time/content-type limits
 */
class SafeFetcher
{
    public const PROFILE_HTML = 'html';

    public const PROFILE_IMAGE = 'image';

    private const BLOCKED_HOST_SUFFIXES = ['.localhost', '.local', '.internal', '.home.arpa', '.lan', '.intranet', '.corp'];

    private const BLOCKED_HOSTS = ['localhost', 'metadata', 'metadata.google.internal', 'instance-data'];

    /** @var array<string, list<string>> */
    private const ACCEPTED_TYPES = [
        self::PROFILE_HTML => ['text/html', 'application/xhtml+xml'],
        self::PROFILE_IMAGE => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
    ];

    /** @var callable|null */
    private $handler;

    public function __construct(private readonly HostResolver $resolver, ?callable $handler = null)
    {
        $this->handler = $handler;
    }

    /**
     * @throws UnsafeUrlException
     * @throws FetchFailedException
     */
    public function fetch(string $url, string $profile = self::PROFILE_HTML): FetchResult
    {
        $maxRedirects = (int) config('stash.fetch.max_redirects', 5);
        $maxBytes = $profile === self::PROFILE_IMAGE
            ? (int) config('stash.fetch.max_image_bytes')
            : (int) config('stash.fetch.max_html_bytes');

        $current = $url;
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $target = $this->validate($current);
            $sink = new LimitedSink($maxBytes);
            $response = $this->send($target, $profile, $maxBytes, $sink);
            $status = $response->getStatusCode();

            if ($status >= 300 && $status < 400 && $response->hasHeader('Location')) {
                $location = trim($response->getHeaderLine('Location'));
                $current = (string) UriResolver::resolve(Utils::uriFor($current), Utils::uriFor($location));

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new FetchFailedException('http_status', "Source responded with HTTP {$status}.", $status);
            }

            [$type, $charset] = $this->contentType($response);
            if (! in_array($type, self::ACCEPTED_TYPES[$profile], true)) {
                throw new FetchFailedException('content_type', 'Source returned an unsupported content type.');
            }

            $body = $sink->contents();
            $truncated = $sink->exceeded;
            if ($truncated && $profile === self::PROFILE_IMAGE) {
                throw new FetchFailedException('too_large', 'Remote image exceeds the size limit.');
            }

            return new FetchResult($current, $status, $type, $charset, $body, $truncated);
        }

        throw new FetchFailedException('too_many_redirects', 'Too many redirects.');
    }

    /**
     * Validate scheme, credentials, port and host, then resolve and check
     * every address. Returns the pinned connection target.
     *
     * @return array{url: string, host: string, port: int, ip: string, is_ip_literal: bool}
     *
     * @throws UnsafeUrlException
     */
    public function validate(string $url): array
    {
        if (! UrlNormalizer::isValidHttpUrl($url)) {
            throw new UnsafeUrlException('invalid_url', 'Only absolute http(s) URLs without credentials are allowed.');
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeUrlException('invalid_url', 'Only absolute http(s) URLs without credentials are allowed.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (! in_array($port, (array) config('stash.fetch.allowed_ports', [80, 443]), true)) {
            throw new UnsafeUrlException('port', 'This port is not allowed.');
        }

        if (preg_match('/[^\x20-\x7E]/', $host) && function_exists('idn_to_ascii')) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
        }

        // IP literal (v4 or v6).
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! IpGuard::isPublic($host)) {
                throw new UnsafeUrlException('private_address', 'Internal or reserved addresses are blocked.');
            }

            return ['url' => $url, 'host' => $host, 'port' => $port, 'ip' => $host, 'is_ip_literal' => true];
        }

        // Numeric hosts that are not canonical IPs (e.g. 2130706433, 0x7f.1) are refused.
        if (preg_match('/^[0-9.]+$/', $host) || preg_match('/^0x/i', $host) || ! str_contains($host, '.')) {
            throw new UnsafeUrlException('invalid_host', 'Host name is not allowed.');
        }

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new UnsafeUrlException('internal_host', 'Internal host names are blocked.');
        }
        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new UnsafeUrlException('internal_host', 'Internal host names are blocked.');
            }
        }

        $ips = $this->resolver->resolve($host);
        if ($ips === []) {
            throw new FetchFailedException('dns', 'The host name could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (! IpGuard::isPublic($ip)) {
                throw new UnsafeUrlException('private_address', 'The host resolves to an internal or reserved address.');
            }
        }

        // Prefer IPv4 for reachability; the chosen address is pinned below.
        usort($ips, fn (string $a, string $b) => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));

        return ['url' => $url, 'host' => $host, 'port' => $port, 'ip' => $ips[0], 'is_ip_literal' => false];
    }

    /**
     * @param  array{url: string, host: string, port: int, ip: string, is_ip_literal: bool}  $target
     */
    private function send(array $target, string $profile, int $maxBytes, LimitedSink $sink): ResponseInterface
    {
        // Protocol and redirect handling go through Guzzle's own options (the
        // installed Guzzle refuses raw CURLOPT_PROTOCOLS/FOLLOWLOCATION); only the
        // DNS pin is passed as a raw cURL option.
        $curl = [];

        if (! $target['is_ip_literal']) {
            $address = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];
            $curl[CURLOPT_RESOLVE] = ["{$target['host']}:{$target['port']}:{$address}"];
        }

        $options = [
            'allow_redirects' => false,
            'protocols' => ['http', 'https'],
            'http_errors' => false,
            'connect_timeout' => (float) config('stash.fetch.connect_timeout', 5),
            'timeout' => (float) config('stash.fetch.timeout', 10),
            'read_timeout' => (float) config('stash.fetch.timeout', 10),
            'verify' => true,
            'proxy' => '',
            'cookies' => false,
            'sink' => $sink,
            'decode_content' => true,
            'headers' => [
                'User-Agent' => (string) config('stash.fetch.user_agent'),
                'Accept' => $profile === self::PROFILE_IMAGE
                    ? 'image/webp,image/png,image/jpeg;q=0.9,image/gif;q=0.5'
                    : 'text/html,application/xhtml+xml;q=0.9',
                'Accept-Language' => 'en,id;q=0.8,*;q=0.5',
            ],
            'curl' => $curl,
            'on_headers' => function (ResponseInterface $response) use ($maxBytes, $profile) {
                $length = $response->getHeaderLine('Content-Length');
                if ($profile === self::PROFILE_IMAGE && $length !== '' && (int) $length > $maxBytes) {
                    throw new FetchFailedException('too_large', 'Remote file exceeds the size limit.');
                }
            },
        ];

        try {
            return $this->client()->request('GET', $target['url'], $options);
        } catch (RequestException $e) {
            // A short write from LimitedSink aborts cURL: keep the capped body.
            if ($sink->exceeded && $e instanceof ResponseException) {
                return $e->getResponse();
            }
            if ($sink->exceeded) {
                throw new FetchFailedException('too_large', 'The source response exceeds the size limit.');
            }
            $previous = $e->getPrevious();
            if ($previous instanceof FetchFailedException) {
                throw $previous;
            }
            throw self::transferFailure($e->getMessage());
        } catch (ConnectException $e) {
            throw self::transferFailure($e->getMessage());
        }
        // Other Guzzle exceptions (e.g. invalid options) are programming errors
        // and intentionally propagate instead of looking like network failures.
    }

    /** Map a cURL/Guzzle transfer error to a sanitized failure category. */
    private static function transferFailure(string $message): FetchFailedException
    {
        $lower = strtolower($message);
        if (str_contains($message, 'cURL error 28') || str_contains($lower, 'timed out')) {
            return new FetchFailedException('timeout', 'The source timed out.');
        }
        if (preg_match('/cURL error (35|51|53|54|58|59|60|64|66|77|80|82|83|90|91)/', $message) || str_contains($lower, 'ssl') || str_contains($lower, 'certificate')) {
            return new FetchFailedException('tls', 'The TLS certificate of the source could not be verified.');
        }
        if (str_contains($message, 'cURL error 6:')) {
            return new FetchFailedException('dns', 'The host name could not be resolved.');
        }

        return new FetchFailedException('connection', 'Could not connect to the source.');
    }

    /** @return array{0: string, 1: string|null} */
    private function contentType(ResponseInterface $response): array
    {
        $header = strtolower($response->getHeaderLine('Content-Type'));
        $type = trim(explode(';', $header)[0]);
        $charset = null;
        if (preg_match('/charset=["\']?([a-z0-9_\-]+)/i', $header, $m)) {
            $charset = $m[1];
        }

        return [$type, $charset];
    }

    private function client(): Client
    {
        // Always the cURL handler: PHP's stream handler would ignore CURLOPT_RESOLVE.
        $config = ['handler' => HandlerStack::create($this->handler ?? new CurlHandler)];

        return new Client($config);
    }
}

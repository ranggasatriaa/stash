<?php

namespace Tests\Feature\Stash;

use App\Stash\Fetch\FetchFailedException;
use App\Stash\Fetch\IpGuard;
use App\Stash\Fetch\SafeFetcher;
use App\Stash\Fetch\UnsafeUrlException;
use App\Stash\Metadata\MetadataEnricher;
use App\Stash\Models\ResourceFile;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\Support\StashTesting;
use Tests\TestCase;

/** Spec §14.3 — SSRF: IPv4/IPv6, redirects, DNS rebinding, unsafe preview URLs. */
class SsrfTest extends TestCase
{
    use RefreshDatabase, StashTesting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStash();
    }

    /** @return array<string, array{string, bool}> */
    public static function addresses(): array
    {
        return [
            'loopback v4' => ['127.0.0.1', false],
            'private 10/8' => ['10.1.2.3', false],
            'private 172.16/12' => ['172.20.0.5', false],
            'private 192.168/16' => ['192.168.1.1', false],
            'cloud metadata' => ['169.254.169.254', false],
            'this network' => ['0.0.0.0', false],
            'CGNAT' => ['100.64.0.1', false],
            'multicast' => ['224.0.0.1', false],
            'reserved' => ['240.0.0.1', false],
            'loopback v6' => ['::1', false],
            'unspecified v6' => ['::', false],
            'ULA (incl. AWS IMDS v6)' => ['fd00:ec2::254', false],
            'link-local v6' => ['fe80::1', false],
            'multicast v6' => ['ff02::1', false],
            'v4-mapped loopback' => ['::ffff:127.0.0.1', false],
            'v4-mapped private' => ['::ffff:10.0.0.1', false],
            'NAT64 loopback' => ['64:ff9b::7f00:1', false],
            '6to4' => ['2002:7f00:1::1', false],
            'teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2', false],
            'documentation v6' => ['2001:db8::1', false],
            'public v4' => ['93.184.216.34', true],
            'public v6' => ['2606:4700:4700::1111', true],
            'v4-mapped public' => ['::ffff:93.184.216.34', true],
        ];
    }

    #[DataProvider('addresses')]
    public function test_ip_guard(string $ip, bool $public): void
    {
        $this->assertSame($public, IpGuard::isPublic($ip), $ip);
    }

    /** @return array<string, array{string}> */
    public static function unsafeUrls(): array
    {
        return [
            'localhost' => ['http://localhost/admin'],
            'localhost subdomain' => ['http://app.localhost/'],
            'loopback literal' => ['http://127.0.0.1:80/'],
            'ipv6 literal' => ['http://[::1]/'],
            'metadata literal' => ['http://169.254.169.254/latest/meta-data/'],
            'decimal ip' => ['http://2130706433/'],
            'hex ip' => ['http://0x7f.0.0.1/'],
            'credentials' => ['https://user:pass@example.com/'],
            'scheme' => ['ftp://example.com/file'],
            'file scheme' => ['file:///etc/passwd'],
            'unsafe port' => ['http://example.com:22/'],
            'internal tld' => ['http://metadata.google.internal/'],
            'single label' => ['http://intranet/'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_urls_are_rejected_before_any_request(string $url): void
    {
        $this->fakeNetwork();
        $this->expectException(UnsafeUrlException::class);
        app(SafeFetcher::class)->fetch($url);
    }

    public function test_hostnames_resolving_to_internal_addresses_are_blocked(): void
    {
        $this->fakeNetwork(['evil.example' => ['93.184.216.34', '10.0.0.5']]);
        $this->expectException(UnsafeUrlException::class);
        app(SafeFetcher::class)->fetch('https://evil.example/');
    }

    public function test_ipv6_only_internal_resolution_is_blocked(): void
    {
        $this->fakeNetwork(['v6.example' => ['fe80::1234']]);
        $this->expectException(UnsafeUrlException::class);
        app(SafeFetcher::class)->fetch('https://v6.example/');
    }

    public function test_connection_is_pinned_to_the_validated_address(): void
    {
        $seen = [];
        $this->fakeNetwork(['pinned.example' => ['93.184.216.34']], [
            function (RequestInterface $request, array $options) use (&$seen) {
                $seen = $options;

                return $this->html('<title>ok</title>');
            },
        ]);

        app(SafeFetcher::class)->fetch('https://pinned.example/page');

        // DNS rebinding defence: cURL connects only to the address we validated.
        $this->assertSame(['pinned.example:443:93.184.216.34'], $seen['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($seen['allow_redirects']);
        $this->assertSame('', $seen['proxy']);
        $this->assertTrue($seen['verify']);
    }

    public function test_request_options_are_accepted_by_the_real_curl_handler(): void
    {
        // Mocks never validate cURL options; build a real easy handle (no network)
        // so an option Guzzle refuses can never be hidden behind the test double.
        $captured = null;
        $this->fakeNetwork(['pinned.example' => ['93.184.216.34']], [
            function (RequestInterface $request, array $options) use (&$captured) {
                $captured = [$request, $options];

                return $this->html('<title>ok</title>');
            },
        ]);
        app(SafeFetcher::class)->fetch('https://pinned.example/page');

        [$request, $options] = $captured;
        $factory = new CurlFactory(1);
        $easy = $factory->create($request, $options);
        $this->assertNotNull($easy->handle);
        $factory->release($easy);
    }

    public function test_redirects_to_internal_hosts_are_revalidated_and_blocked(): void
    {
        $this->fakeNetwork([], [new Response(302, ['Location' => 'http://127.0.0.1:8080/admin'])]);
        $this->expectException(UnsafeUrlException::class);
        app(SafeFetcher::class)->fetch('https://public.example/start');
    }

    public function test_relative_redirects_to_rebinding_hosts_are_blocked(): void
    {
        $this->fakeNetwork(['public.example' => ['93.184.216.34'], 'rebind.example' => ['192.168.0.10']], [
            new Response(301, ['Location' => '/next']),
            new Response(302, ['Location' => 'https://rebind.example/secret']),
        ]);
        $this->expectException(UnsafeUrlException::class);
        app(SafeFetcher::class)->fetch('https://public.example/');
    }

    public function test_too_many_redirects_and_size_limits(): void
    {
        config(['stash.fetch.max_redirects' => 2]);
        $loop = array_fill(0, 5, new Response(302, ['Location' => 'https://public.example/again']));
        $this->fakeNetwork([], $loop);
        try {
            app(SafeFetcher::class)->fetch('https://public.example/');
            $this->fail('expected redirect limit');
        } catch (FetchFailedException $e) {
            $this->assertSame('too_many_redirects', $e->category);
        }

        config(['stash.fetch.max_image_bytes' => 1000]);
        $this->fakeNetwork([], [new Response(200, ['Content-Type' => 'image/png'], str_repeat('x', 5000))]);
        $this->expectException(FetchFailedException::class);
        app(SafeFetcher::class)->fetch('https://public.example/big.png', SafeFetcher::PROFILE_IMAGE);
    }

    public function test_html_bodies_are_capped_not_fully_buffered(): void
    {
        config(['stash.fetch.max_html_bytes' => 100]);
        $this->fakeNetwork([], [$this->html('<title>t</title>'.str_repeat('a', 10000))]);
        $result = app(SafeFetcher::class)->fetch('https://public.example/');
        $this->assertTrue($result->truncated);
        $this->assertSame(100, strlen($result->body));
    }

    public function test_unsafe_preview_and_favicon_urls_are_ignored(): void
    {
        $page = '<html><head><title>Hello</title><meta property="og:image" content="http://169.254.169.254/latest/meta-data/iam">'
            .'<link rel="icon" href="http://10.0.0.1/favicon.ico"></head></html>';
        $this->fakeNetwork([], [$this->html($page)]);
        $user = $this->owner();
        $r = $this->resource($user, ['original_url' => 'https://public.example/post']);

        $this->assertSame('ready', app(MetadataEnricher::class)->enrich($r->id));
        $r->refresh();
        $this->assertSame('Hello', $r->title);
        $this->assertNull($r->metadata['fetched']['favicon_url']);
        $this->assertSame(0, ResourceFile::query()->count(), 'no preview fetched from an internal address');
        $this->assertSame(0, $this->http->count(), 'only the page request was made');
    }

    public function test_saving_an_internal_url_is_allowed_but_never_fetched(): void
    {
        $this->fakeNetwork();
        $user = $this->owner();
        $r = $this->resource($user, ['original_url' => 'http://192.168.1.10/router']);

        $this->assertSame('failed', app(MetadataEnricher::class)->enrich($r->id));
        $this->assertStringContainsString('not allowed', (string) $r->fresh()->metadata_error);
    }
}

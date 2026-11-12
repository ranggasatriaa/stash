<?php

namespace Tests\Support;

use App\Models\User;
use App\Stash\Fetch\HostResolver;
use App\Stash\Fetch\SafeFetcher;
use App\Stash\Models\Resource;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Shared helpers for Stash feature tests (PostgreSQL-backed). */
trait StashTesting
{
    protected MockHandler $http;

    protected function setUpStash(): void
    {
        Storage::fake('stash');
        config(['stash.disk' => 'stash']);
    }

    protected function owner(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['timezone' => 'UTC']);
    }

    /** @param array<string, mixed> $attributes */
    protected function resource(User $user, array $attributes = []): Resource
    {
        return Resource::factory()->for($user)->create($attributes);
    }

    /**
     * Replace the network: DNS answers come from $dns (host => ips) and HTTP
     * responses/callables are queued on a Guzzle MockHandler.
     *
     * @param  array<string, list<string>>  $dns
     * @param  list<mixed>  $responses
     */
    protected function fakeNetwork(array $dns = [], array $responses = []): MockHandler
    {
        $resolver = new class($dns) implements HostResolver
        {
            /** @param array<string, list<string>> $map */
            public function __construct(private array $map) {}

            public function resolve(string $host): array
            {
                return $this->map[$host] ?? ['93.184.216.34'];
            }
        };

        $this->http = new MockHandler($responses);
        $this->app->instance(HostResolver::class, $resolver);
        $this->app->bind(SafeFetcher::class, fn ($app) => new SafeFetcher($resolver, $this->http));

        return $this->http;
    }

    protected function html(string $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    protected function pngBytes(int $width = 120, int $height = 80): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefilledrectangle($img, 0, 0, $width, $height, (int) imagecolorallocate($img, 255, 216, 61));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    protected function jpegBytes(int $width = 120, int $height = 80): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefilledrectangle($img, 0, 0, $width, $height, (int) imagecolorallocate($img, 255, 116, 23));
        ob_start();
        imagejpeg($img, null, 90);

        return (string) ob_get_clean();
    }

    protected function uploadedPng(string $name = 'shot.png', int $width = 120, int $height = 80): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pngBytes($width, $height));
    }

    /**
     * JPEG with an EXIF APP1 segment containing Orientation=6 (rotate 90° CW)
     * and a GPS IFD pointer, to verify orientation handling and metadata
     * stripping in generated previews.
     */
    protected function jpegWithExifOrientation(int $width = 200, int $height = 100): string
    {
        $jpeg = $this->jpegBytes($width, $height);

        // TIFF header (little endian) + IFD0 with 2 entries: Orientation, GPSInfo pointer.
        $tiff = 'II'.pack('v', 42).pack('V', 8);
        $tiff .= pack('v', 2);
        $tiff .= pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', 6).pack('v', 0);   // Orientation = 6
        $tiff .= pack('v', 0x8825).pack('v', 4).pack('V', 1).pack('V', 38);               // GPS IFD at offset 38
        $tiff .= pack('V', 0);                                                             // next IFD
        // GPS IFD: 1 entry GPSLatitudeRef = 'N'
        $tiff .= pack('v', 1).pack('v', 0x0001).pack('v', 2).pack('V', 2).'N'."\0\0\0".pack('V', 0);

        $exif = "Exif\0\0".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    /** @param list<string> $abilities */
    protected function apiToken(User $user, array $abilities = ['*'], ?\DateTimeInterface $expires = null): string
    {
        return $user->createToken('test', $abilities, $expires)->plainTextToken;
    }

    /** @return array<string, string> */
    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }
}

<?php

namespace App\Stash\Fetch;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Response sink that stops accepting bytes after a cap. Returning a short
 * write makes cURL abort the transfer, so oversized bodies (including
 * decompression bombs) are never fully downloaded.
 */
final class LimitedSink implements StreamInterface
{
    use StreamDecoratorTrait;

    public bool $exceeded = false;

    private int $written = 0;

    private StreamInterface $stream;

    public function __construct(private readonly int $maxBytes)
    {
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new RuntimeException('Unable to open a temporary stream.');
        }

        $this->stream = Utils::streamFor($resource);
    }

    public function write(string $string): int
    {
        $remaining = $this->maxBytes - $this->written;
        if (strlen($string) > $remaining) {
            if ($remaining > 0) {
                $this->stream->write(substr($string, 0, $remaining));
                $this->written += $remaining;
            }
            $this->exceeded = true;

            return 0;
        }

        $this->written += strlen($string);

        return $this->stream->write($string);
    }

    public function contents(): string
    {
        $this->stream->rewind();

        return $this->stream->getContents();
    }
}

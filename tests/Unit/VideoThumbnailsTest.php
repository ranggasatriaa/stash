<?php

namespace Tests\Unit;

use App\Stash\Metadata\HtmlMetadataParser;
use App\Stash\Metadata\VideoThumbnails;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VideoThumbnailsTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function urls(): array
    {
        return [
            'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=5', 'dQw4w9WgXcQ'],
            'short link' => ['https://youtu.be/dQw4w9WgXcQ?si=x', 'dQw4w9WgXcQ'],
            'shorts' => ['https://m.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'embed' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'bad id' => ['https://www.youtube.com/watch?v=short', null],
            'other site' => ['https://example.com/watch?v=dQw4w9WgXcQ', null],
        ];
    }

    #[DataProvider('urls')]
    public function test_youtube_id(string $url, ?string $id): void
    {
        $this->assertSame($id, VideoThumbnails::youtubeId($url));
        $this->assertCount($id ? 3 : 0, VideoThumbnails::candidates($url));
    }

    public function test_parser_collects_ordered_unique_image_candidates(): void
    {
        $html = '<html><head>
            <meta property="og:image" content="/a.jpg">
            <meta name="twitter:image" content="/a.jpg">
            <link rel="image_src" href="https://cdn.example.com/b.png">
        </head><body><video poster="c.webp"></video></body></html>';

        $parsed = (new HtmlMetadataParser)->parse($html, 'https://example.com/post/');

        $this->assertSame('https://example.com/a.jpg', $parsed['og_image']);
        $this->assertSame([
            'https://example.com/a.jpg',
            'https://cdn.example.com/b.png',
            'https://example.com/post/c.webp',
        ], $parsed['image_candidates']);
    }
}

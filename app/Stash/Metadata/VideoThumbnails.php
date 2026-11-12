<?php

namespace App\Stash\Metadata;

/**
 * Thumbnail URLs for well-known video hosts that can be derived from the page
 * URL alone, so a video still gets a preview when its page is slow, behind a
 * consent wall, or has no usable Open Graph image. The URLs are fetched
 * through the hardened fetcher like any other image.
 */
final class VideoThumbnails
{
    /** @return list<string> candidate image URLs, best first (possibly empty) */
    public static function candidates(string $url): array
    {
        $id = self::youtubeId($url);
        if ($id === null) {
            return [];
        }

        return [
            "https://i.ytimg.com/vi/{$id}/maxresdefault.jpg",
            "https://i.ytimg.com/vi/{$id}/sddefault.jpg",
            "https://i.ytimg.com/vi/{$id}/hqdefault.jpg",
        ];
    }

    public static function youtubeId(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = strtolower(preg_replace('/^(www|m|music)\./i', '', $parts['host']) ?? '');
        $path = $parts['path'] ?? '';
        $id = null;

        if ($host === 'youtu.be') {
            $id = trim($path, '/');
        } elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            if ($path === '/watch') {
                parse_str($parts['query'] ?? '', $query);
                $id = is_string($query['v'] ?? null) ? $query['v'] : null;
            } elseif (preg_match('#^/(?:embed|shorts|live|v)/([^/?]+)#', $path, $m)) {
                $id = $m[1];
            }
        }

        return $id !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1 ? $id : null;
    }
}

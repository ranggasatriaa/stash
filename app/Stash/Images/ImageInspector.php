<?php

namespace App\Stash\Images;

use finfo;

/**
 * Validates image bytes *before* any expensive decoding: size, magic-byte
 * MIME type, header-declared type, dimensions and total pixel count.
 * Client-supplied names and MIME types are never trusted.
 */
final class ImageInspector
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    private const IMAGETYPES = [
        'image/jpeg' => IMAGETYPE_JPEG,
        'image/png' => IMAGETYPE_PNG,
        'image/webp' => IMAGETYPE_WEBP,
        'image/gif' => IMAGETYPE_GIF,
    ];

    /**
     * @param  list<string>|null  $allowedMimes
     *
     * @throws ImageRejected
     */
    public function inspect(string $path, ?array $allowedMimes = null, ?int $maxBytes = null): ImageInfo
    {
        $allowedMimes ??= (array) config('stash.uploads.allowed_mimes');
        $maxBytes ??= (int) config('stash.uploads.max_bytes');
        $maxPixels = (int) config('stash.uploads.max_pixels');
        $maxDimension = (int) config('stash.uploads.max_dimension');

        if (! is_file($path) || ! is_readable($path)) {
            throw new ImageRejected('unreadable', 'The file could not be read.');
        }

        $size = (int) filesize($path);
        if ($size <= 0) {
            throw new ImageRejected('empty', 'The file is empty.');
        }
        if ($size > $maxBytes) {
            throw new ImageRejected('too_large', sprintf('The image is %s; the limit is %s.', self::humanBytes($size), self::humanBytes($maxBytes)));
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        if (! in_array($mime, $allowedMimes, true)) {
            throw new ImageRejected('type', 'Only JPEG, PNG and WebP images are supported (detected '.self::safeMime($mime).').');
        }

        $info = @getimagesize($path);
        if ($info === false || $info[2] !== self::IMAGETYPES[$mime]) {
            throw new ImageRejected('corrupt', 'The file content does not match a valid '.strtoupper(self::EXTENSIONS[$mime]).' image.');
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if ($width < 1 || $height < 1) {
            throw new ImageRejected('corrupt', 'The image has invalid dimensions.');
        }
        if ($width > $maxDimension || $height > $maxDimension) {
            throw new ImageRejected('dimensions', sprintf('The image is %d×%d px; each side must be at most %d px.', $width, $height, $maxDimension));
        }
        if ($width * $height > $maxPixels) {
            throw new ImageRejected('pixels', sprintf(
                'The image has %s megapixels (%d×%d); the limit is %s megapixels.',
                self::megapixels($width * $height), $width, $height, self::megapixels($maxPixels),
            ));
        }

        return new ImageInfo($mime, self::EXTENSIONS[$mime], $width, $height, $size, (string) hash_file('sha256', $path));
    }

    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) $bytes : number_format($value, 1)).' '.$units[$i];
    }

    private static function megapixels(int $pixels): string
    {
        return rtrim(rtrim(number_format($pixels / 1_000_000, 2, '.', ''), '0'), '.') ?: '0';
    }

    private static function safeMime(string $mime): string
    {
        return preg_replace('/[^a-z0-9.+\/-]/i', '', $mime) ?: 'unknown';
    }
}

<?php

namespace App\Stash\Images;

/**
 * Validates an uploaded video by content, never by client-supplied name or
 * type: size, then server-side MIME detection against the allowlist.
 */
final class VideoInspector
{
    private const EXTENSIONS = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
    ];

    /** Detected MIME aliases mapped to their canonical allowlisted type. */
    private const ALIASES = [
        'video/x-m4v' => 'video/mp4',
        'video/x-matroska' => 'video/webm',
    ];

    public static function isVideo(string $path): bool
    {
        return str_starts_with(self::detect($path), 'video/');
    }

    /** @throws ImageRejected */
    public function inspect(string $path): ImageInfo
    {
        $maxBytes = (int) config('stash.videos.max_bytes');
        $allowed = (array) config('stash.videos.allowed_mimes');

        $size = @filesize($path);
        if ($size === false || ! is_readable($path)) {
            throw new ImageRejected('unreadable', 'The video could not be read.');
        }
        if ($size === 0) {
            throw new ImageRejected('empty', 'The video file is empty.');
        }
        if ($size > $maxBytes) {
            throw new ImageRejected('too_large', sprintf('The video is %s; the limit is %s.', ImageInspector::humanBytes($size), ImageInspector::humanBytes($maxBytes)));
        }

        $mime = self::detect($path);
        $mime = self::ALIASES[$mime] ?? $mime;
        if (! in_array($mime, $allowed, true) || ! isset(self::EXTENSIONS[$mime])) {
            throw new ImageRejected('type', 'Only MP4, WebM and MOV videos are supported.');
        }

        $sha = hash_file('sha256', $path);
        if ($sha === false) {
            throw new ImageRejected('unreadable', 'The video could not be read.');
        }

        return new ImageInfo($mime, self::EXTENSIONS[$mime], 0, 0, $size, $sha);
    }

    private static function detect(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return is_string($mime) ? strtolower($mime) : '';
    }
}

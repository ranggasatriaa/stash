<?php

namespace App\Stash\Transfer;

use App\Stash\Exceptions\StashException;
use RuntimeException;
use ZipArchive;

/**
 * Defensive ZIP reader for imports. Entries are never extracted by name:
 * they are streamed into server-named temp files with byte caps.
 *
 * Rejects: absolute paths, drive letters, backslashes, NUL bytes, `..`/`.`
 * segments, symlinks, encrypted entries, too many entries, excessive total
 * expanded size, suspicious compression ratios (zip bombs), and names
 * outside the allow-list (manifest.json, data.json, attachments/<id>.<ext>).
 */
final class SafeZipReader
{
    private const ATTACHMENT_PATTERN = '#^attachments/[0-9a-hjkmnp-tv-z]{26}\.(jpg|jpeg|png|webp|gif)$#i';

    private ZipArchive $zip;

    /** @var array<string, array{index: int, size: int, comp_size: int}> */
    private array $entries = [];

    /** @var list<string> */
    public array $warnings = [];

    public function __construct(string $path)
    {
        $this->zip = new ZipArchive;
        $flags = defined('ZipArchive::RDONLY') ? ZipArchive::RDONLY : 0;
        if ($this->zip->open($path, $flags) !== true) {
            throw new StashException('invalid_archive', 'The file is not a readable ZIP archive.', 422);
        }

        $this->scan();
    }

    private bool $closed = false;

    public function close(): void
    {
        if (! $this->closed) {
            $this->closed = true;
            try {
                $this->zip->close();
            } catch (\Throwable) {
                // already closed
            }
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /** @return list<string> */
    public function attachmentNames(): array
    {
        return array_values(array_filter(array_keys($this->entries), fn ($n) => str_starts_with($n, 'attachments/')));
    }

    public function size(string $name): int
    {
        return $this->entries[$name]['size'] ?? 0;
    }

    /** Read a (small) entry into memory with a hard cap. */
    public function read(string $name, int $maxBytes): string
    {
        $tmp = $this->extractToTemp($name, $maxBytes);
        try {
            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Stream an entry into a new temp file (server-chosen name). The caller
     * must delete the returned path.
     */
    public function extractToTemp(string $name, int $maxBytes): string
    {
        if (! isset($this->entries[$name])) {
            throw new StashException('missing_entry', "The archive does not contain {$name}.", 422);
        }

        $declared = $this->entries[$name]['size'];
        if ($declared > $maxBytes) {
            throw new StashException('entry_too_large', "{$name} exceeds the allowed size.", 422);
        }

        $stream = $this->zip->getStream($name);
        if ($stream === false) {
            throw new StashException('unreadable_entry', "{$name} could not be read.", 422);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'stashz');
        if ($tmp === false) {
            throw new RuntimeException('Unable to allocate a temporary file.');
        }

        $out = fopen($tmp, 'wb');
        if ($out === false) {
            @unlink($tmp);
            fclose($stream);
            throw new RuntimeException('Unable to open a temporary file for writing.');
        }

        $written = 0;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    break;
                }
                $written += strlen($chunk);
                // Never trust the header: enforce the declared and global caps while streaming.
                if ($written > $maxBytes || $written > $declared) {
                    throw new StashException('entry_too_large', "{$name} expands beyond its declared size.", 422);
                }
                fwrite($out, $chunk);
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmp);
            throw $e;
        } finally {
            fclose($stream);
        }
        fclose($out);

        return $tmp;
    }

    private function scan(): void
    {
        $maxEntries = (int) config('stash.imports.max_entries', 20000);
        $maxExpanded = (int) config('stash.imports.max_expanded_bytes');
        $maxRatio = (int) config('stash.imports.max_compression_ratio', 200);

        $count = $this->zip->numFiles;
        if ($count > $maxEntries) {
            throw new StashException('too_many_entries', "The archive has {$count} entries; the limit is {$maxEntries}.", 422);
        }

        $total = 0;
        for ($i = 0; $i < $count; $i++) {
            $stat = $this->zip->statIndex($i, ZipArchive::FL_UNCHANGED);
            if ($stat === false) {
                throw new StashException('invalid_archive', 'The archive index is corrupt.', 422);
            }
            $name = (string) $stat['name'];

            $this->assertSafeName($name);

            if ($stat['encryption_method'] !== 0) {
                throw new StashException('encrypted_entry', 'Encrypted archives are not supported.', 422);
            }

            $opsys = 0;
            $attr = 0;
            if ($this->zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX) {
                $mode = ($attr >> 16) & 0170000;
                if ($mode === 0120000) {
                    throw new StashException('symlink_entry', 'Archives containing symbolic links are rejected.', 422);
                }
            }

            if (str_ends_with($name, '/')) {
                continue;
            }
            if (str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') {
                continue;
            }

            if ($name !== 'manifest.json' && $name !== 'data.json' && ! preg_match(self::ATTACHMENT_PATTERN, $name)) {
                $this->warnings[] = 'Ignored unexpected entry: '.mb_substr($name, 0, 120);

                continue;
            }

            $size = (int) $stat['size'];
            $compressed = max(1, (int) $stat['comp_size']);
            if ($size > 1024 * 1024 && $size / $compressed > $maxRatio) {
                throw new StashException('suspicious_compression', 'The archive looks like a compression bomb and was rejected.', 422);
            }

            $total += $size;
            if ($total > $maxExpanded) {
                throw new StashException('archive_too_large', 'The archive expands beyond the allowed size.', 422);
            }

            if (isset($this->entries[$name])) {
                throw new StashException('duplicate_entry', 'The archive contains duplicate entry names.', 422);
            }
            $this->entries[$name] = ['index' => $i, 'size' => $size, 'comp_size' => $compressed];
        }
    }

    private function assertSafeName(string $name): void
    {
        $unsafe = $name === ''
            || str_contains($name, "\0")
            || str_contains($name, '\\')
            || str_starts_with($name, '/')
            || preg_match('/^[a-zA-Z]:/', $name)
            || strlen($name) > 255;

        if (! $unsafe) {
            foreach (explode('/', rtrim($name, '/')) as $segment) {
                if ($segment === '..' || $segment === '.' || $segment === '') {
                    $unsafe = true;
                    break;
                }
            }
        }

        if ($unsafe) {
            throw new StashException('unsafe_path', 'The archive contains an unsafe path and was rejected.', 422);
        }
    }
}

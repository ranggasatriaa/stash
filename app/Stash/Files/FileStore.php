<?php

namespace App\Stash\Files;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Private file storage behind a Laravel disk. Keys are generated server-side
 * (never from client input), confined to the disk root, and owner-scoped.
 */
class FileStore
{
    public function diskName(): string
    {
        return (string) config('stash.disk', 'stash');
    }

    public function disk(?string $name = null): Filesystem
    {
        return Storage::disk($name ?? $this->diskName());
    }

    public function resourceKey(int $userId, string $resourceId, string $role, string $extension): string
    {
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: 'bin';

        return sprintf('users/%d/resources/%s/%s-%s.%s', $userId, $resourceId, $role, Str::lower((string) Str::ulid()), $extension);
    }

    public function exportKey(int $userId, string $exportId, string $extension): string
    {
        return sprintf('users/%d/exports/%s.%s', $userId, $exportId, $extension);
    }

    public function importKey(int $userId, string $importId, string $extension): string
    {
        return sprintf('users/%d/imports/%s.%s', $userId, $importId, $extension);
    }

    /** Copy a local file into the store. */
    public function putFile(string $key, string $localPath): void
    {
        $this->assertSafeKey($key);
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open source file.');
        }
        try {
            $this->disk()->writeStream($key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function put(string $key, string $contents): void
    {
        $this->assertSafeKey($key);
        $this->disk()->put($key, $contents);
    }

    /**
     * Delete a file; already-missing files are treated as success so retries
     * stay idempotent.
     */
    public function delete(string $disk, string $key): bool
    {
        $this->assertSafeKey($key);
        try {
            $fs = $this->disk($disk);
            if (! $fs->exists($key)) {
                return true;
            }

            return $fs->delete($key);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Run a callback with a local filesystem path for the stored file,
     * copying it to a temporary file when the disk is not local.
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    public function withLocalPath(string $disk, string $key, callable $callback): mixed
    {
        $this->assertSafeKey($key);
        $fs = $this->disk($disk);
        $adapterPath = $fs->path($key);
        if ($adapterPath && is_file($adapterPath)) {
            return $callback($adapterPath);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'stash');
        if ($tmp === false) {
            throw new RuntimeException('Unable to allocate a temporary file.');
        }
        try {
            $in = $fs->readStream($key);
            $out = fopen($tmp, 'wb');
            if (! is_resource($in) || $out === false) {
                throw new RuntimeException('Stored file is missing.');
            }
            stream_copy_to_stream($in, $out);
            fclose($out);
            fclose($in);

            return $callback($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function assertSafeKey(string $key): void
    {
        if ($key === '' || str_contains($key, '..') || str_starts_with($key, '/') || str_contains($key, '\\') || str_contains($key, "\0")) {
            throw new RuntimeException('Unsafe storage key.');
        }
    }
}

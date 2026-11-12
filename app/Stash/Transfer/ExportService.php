<?php

namespace App\Stash\Transfer;

use App\Models\User;
use App\Stash\Activity\ActivityLogger;
use App\Stash\Exceptions\StashException;
use App\Stash\Files\FileStore;
use App\Stash\Jobs\GenerateExport;
use App\Stash\Models\DataExport;
use App\Stash\Models\ResourceFile;
use RuntimeException;
use ZipArchive;

/**
 * Queued, owner-scoped exports: JSON metadata, ZIP (JSON + originals +
 * manifest with checksums) and an Obsidian Markdown snapshot. Files are
 * private, require authentication to download and expire.
 */
final class ExportService
{
    public const FORMATS = ['json', 'zip', 'obsidian'];

    public function __construct(
        private readonly ExportDataBuilder $builder,
        private readonly ObsidianWriter $obsidian,
        private readonly FileStore $files,
        private readonly ActivityLogger $activity,
    ) {}

    public function request(User $user, string $format, bool $includeTrash): DataExport
    {
        if (! in_array($format, self::FORMATS, true)) {
            throw new StashException('invalid_format', 'Choose json, zip or obsidian.', 422, ['format' => ['Choose json, zip or obsidian.']]);
        }

        $active = DataExport::query()->where('user_id', $user->id)->whereIn('status', ['queued', 'processing'])->count();
        if ($active >= 3) {
            throw new StashException('too_many_exports', 'Wait for running exports to finish first.', 429);
        }

        $export = new DataExport(['format' => $format, 'include_trash' => $includeTrash, 'status' => 'queued']);
        $export->user_id = $user->id;
        $export->save();

        $this->activity->record($user->id, 'export.requested', 'export', $export->id, ['format' => $format, 'include_trash' => $includeTrash]);
        GenerateExport::dispatch($export->id);

        return $export;
    }

    public function generate(DataExport $export): void
    {
        $user = User::query()->findOrFail($export->user_id);
        $export->update(['status' => 'processing', 'error' => null]);

        $tmp = tempnam(sys_get_temp_dir(), 'stashx');
        if ($tmp === false) {
            throw new RuntimeException('Unable to allocate a temporary file.');
        }

        try {
            $withAttachments = $export->format !== 'json';
            ['document' => $document, 'attachments' => $attachments] = $this->builder->build($user, $export->include_trash, $withAttachments);
            $stamp = now()->format('Ymd-His');

            if ($export->format === 'json') {
                file_put_contents($tmp, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $extension = 'json';
                $filename = "stash-export-{$stamp}.json";
            } else {
                $this->writeZip($tmp, $export->format, $document, $attachments);
                $extension = 'zip';
                $filename = $export->format === 'obsidian' ? "stash-obsidian-{$stamp}.zip" : "stash-export-{$stamp}.zip";
            }

            $key = $this->files->exportKey($user->id, $export->id, $extension);
            $this->files->putFile($key, $tmp);

            $export->update([
                'status' => 'ready',
                'disk' => $this->files->diskName(),
                'storage_key' => $key,
                'filename' => $filename,
                'size_bytes' => filesize($tmp),
                'checksum_sha256' => hash_file('sha256', $tmp),
                'stats' => $document['counts'],
                'expires_at' => now()->addHours((int) config('stash.exports.ttl_hours', 72)),
            ]);
            $this->activity->record($user->id, 'export.ready', 'export', $export->id, $document['counts']);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @param  array{projects: array<int, array<string, mixed>>, tags: array<int, array<string, mixed>>, resources: array<int, array<string, mixed>>, exported_at: string, counts: array{resources: int, projects: int, tags: int, attachments: int}, ...}  $document
     * @param  list<ResourceFile>  $attachments
     */
    private function writeZip(string $path, string $format, array $document, array $attachments): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the archive.');
        }

        $temps = [];
        $manifestFiles = [];

        try {
            foreach ($attachments as $file) {
                $entry = ExportDataBuilder::attachmentPath($file);
                $local = $this->files->withLocalPath($file->disk, $file->storage_key, function (string $p) use (&$temps) {
                    $copy = tempnam(sys_get_temp_dir(), 'stasha');
                    if ($copy === false || ! copy($p, $copy)) {
                        throw new RuntimeException('Unable to stage an attachment.');
                    }
                    $temps[] = $copy;

                    return $copy;
                });
                $zip->addFile($local, $entry);
                $zip->setCompressionName($entry, ZipArchive::CM_STORE);
                $manifestFiles[] = ['path' => $entry, 'sha256' => hash_file('sha256', $local), 'size' => filesize($local)];
            }

            if ($format === 'obsidian') {
                foreach ($this->obsidian->notes($document) as $notePath => $contents) {
                    $zip->addFromString($notePath, $contents);
                    $manifestFiles[] = ['path' => $notePath, 'sha256' => hash('sha256', $contents), 'size' => strlen($contents)];
                }
            } else {
                $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $zip->addFromString('data.json', $json);
                $manifestFiles[] = ['path' => 'data.json', 'sha256' => hash('sha256', $json), 'size' => strlen($json)];
            }

            $manifest = [
                'schema' => 'stash.export.manifest',
                'schema_version' => ExportDataBuilder::SCHEMA_VERSION,
                'format' => $format,
                'created_at' => now()->toIso8601ZuluString(),
                'counts' => $document['counts'],
                'files' => $manifestFiles,
            ];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            if (! $zip->close()) {
                throw new RuntimeException('Unable to finalize the archive.');
            }
        } finally {
            foreach ($temps as $t) {
                @unlink($t);
            }
        }
    }

    public function prune(): int
    {
        $count = 0;
        DataExport::query()->where('expires_at', '<', now())->whereNotNull('storage_key')->each(function (DataExport $export) use (&$count) {
            if ($export->disk && $export->storage_key) {
                $this->files->delete($export->disk, $export->storage_key);
            }
            $export->update(['status' => 'expired', 'storage_key' => null]);
            $count++;
        });

        return $count;
    }
}

<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Stash\Models\ResourceFile;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams private files after an owner check. Content-Type comes from the
 * server-side detection at upload time; responses are sandboxed and never
 * sniffed. Shared by the web (session) and API (token) routes.
 */
class FileController extends Controller
{
    public function show(Request $request, ResourceFile $file): Response
    {
        Gate::authorize('view', $file);
        abort_unless($file->status === 'ready', 404);

        $fs = Storage::disk($file->disk);
        abort_unless($fs->exists($file->storage_key), 404);

        $title = Str::slug(Str::limit((string) $file->resource?->title, 60, '')) ?: 'stash';
        $name = $title.'-'.$file->role->value.'.'.$file->extension;
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        $headers = [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=604800, immutable',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        // Videos need HTTP Range support for seeking; Symfony's file response
        // provides it on local disks.
        if (str_starts_with($file->mime_type, 'video/') && $fs instanceof FilesystemAdapter && $fs->getAdapter() instanceof LocalFilesystemAdapter) {
            return response()->file($fs->path($file->storage_key), $headers + [
                'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $name, Str::ascii($name)),
            ]);
        }

        return $fs->response($file->storage_key, $name, $headers, $disposition);
    }
}

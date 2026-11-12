<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Stash\Models\ResourceFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams private files after an owner check. Content-Type comes from the
 * server-side detection at upload time; responses are sandboxed and never
 * sniffed. Shared by the web (session) and API (token) routes.
 */
class FileController extends Controller
{
    public function show(Request $request, ResourceFile $file): StreamedResponse
    {
        Gate::authorize('view', $file);
        abort_unless($file->status === 'ready', 404);

        $fs = Storage::disk($file->disk);
        abort_unless($fs->exists($file->storage_key), 404);

        $title = Str::slug(Str::limit((string) $file->resource?->title, 60, '')) ?: 'stash';
        $name = $title.'-'.$file->role->value.'.'.$file->extension;
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return $fs->response($file->storage_key, $name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=604800, immutable',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ], $disposition);
    }
}

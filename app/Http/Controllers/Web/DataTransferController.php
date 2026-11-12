<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Stash\Models\DataExport;
use App\Stash\Models\DataImport;
use App\Stash\Transfer\ExportService;
use App\Stash\Transfer\ImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataTransferController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/data', [
            'exports' => DataExport::query()->where('user_id', $user->id)->latest()->limit(20)->get()
                ->map(fn (DataExport $e) => self::exportJson($e)),
            'imports' => DataImport::query()->where('user_id', $user->id)->latest()->limit(10)->get()
                ->map(fn (DataImport $i) => self::importJson($i)),
            'storage' => self::storageUsage($user),
            'import_max_bytes' => (int) config('stash.imports.max_upload_bytes'),
        ]);
    }

    public function export(Request $request, ExportService $exports): RedirectResponse
    {
        $data = $request->validate([
            'format' => ['required', 'string', 'in:'.implode(',', ExportService::FORMATS)],
            'include_trash' => ['nullable', 'boolean'],
        ]);
        $exports->request($request->user(), $data['format'], (bool) ($data['include_trash'] ?? false));
        Inertia::flash('toast', ['type' => 'info', 'message' => 'Export queued. It will appear below when ready.']);

        return back();
    }

    public function download(Request $request, DataExport $export): StreamedResponse
    {
        Gate::authorize('view', $export);
        abort_if($export->status !== 'ready' || ! $export->storage_key, 404);
        abort_if($export->isExpired(), 410, 'This export has expired.');

        return Storage::disk((string) $export->disk)->download((string) $export->storage_key, (string) $export->filename, [
            'Content-Type' => str_ends_with((string) $export->filename, '.json') ? 'application/json' : 'application/zip',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function upload(Request $request, ImportService $imports): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file']]);
        $imports->upload($request->user(), $request->file('file'));
        Inertia::flash('toast', ['type' => 'info', 'message' => 'Import uploaded. Analyzing…']);

        return back();
    }

    public function apply(Request $request, DataImport $import, ImportService $imports): RedirectResponse
    {
        Gate::authorize('update', $import);
        $data = $request->validate(['collision_strategy' => ['required', 'string', 'in:'.implode(',', ImportService::STRATEGIES)]]);
        $imports->confirm($request->user(), $import, $data['collision_strategy']);
        Inertia::flash('toast', ['type' => 'info', 'message' => 'Import started.']);

        return back();
    }

    public function discard(Request $request, DataImport $import, ImportService $imports): RedirectResponse
    {
        Gate::authorize('delete', $import);
        $imports->discard($request->user(), $import);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Import removed.']);

        return back();
    }

    /** @return array<string, mixed> */
    public static function exportJson(DataExport $e): array
    {
        return [
            'id' => $e->id,
            'format' => $e->format,
            'include_trash' => $e->include_trash,
            'status' => $e->isExpired() && $e->status === 'ready' ? 'expired' : $e->status,
            'filename' => $e->filename,
            'size_bytes' => $e->size_bytes,
            'checksum_sha256' => $e->checksum_sha256,
            'stats' => $e->stats,
            'error' => $e->error,
            'expires_at' => $e->expires_at?->toIso8601ZuluString(),
            'created_at' => $e->created_at->toIso8601ZuluString(),
            'download_url' => $e->status === 'ready' && ! $e->isExpired() ? route('exports.download', $e) : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function importJson(DataImport $i): array
    {
        return [
            'id' => $i->id,
            'format' => $i->format,
            'status' => $i->status,
            'original_filename' => $i->original_filename,
            'size_bytes' => $i->size_bytes,
            'collision_strategy' => $i->collision_strategy,
            'preview' => $i->preview,
            'report' => $i->report,
            'error' => $i->error,
            'created_at' => $i->created_at->toIso8601ZuluString(),
        ];
    }

    /** @return array<string, mixed> */
    public static function storageUsage(User $user): array
    {
        $files = DB::table('resource_files')->where('user_id', $user->id)
            ->selectRaw('role, count(*) AS files, coalesce(sum(size_bytes), 0) AS bytes')->groupBy('role')->get()
            ->mapWithKeys(fn ($r) => [$r->role => ['files' => (int) $r->files, 'bytes' => (int) $r->bytes]]);
        $exports = (int) DB::table('data_exports')->where('user_id', $user->id)->whereNotNull('storage_key')->sum('size_bytes');
        $imports = (int) DB::table('data_imports')->where('user_id', $user->id)->sum('size_bytes');

        return [
            'by_role' => $files,
            'exports_bytes' => $exports,
            'imports_bytes' => $imports,
            'total_bytes' => (int) $files->sum('bytes') + $exports + $imports,
            'resources' => DB::table('resources')->where('user_id', $user->id)->count(),
        ];
    }
}

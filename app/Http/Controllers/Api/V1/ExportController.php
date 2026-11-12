<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\DataTransferController;
use App\Stash\Models\DataExport;
use App\Stash\Transfer\ExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function store(Request $request, ExportService $exports): JsonResponse
    {
        $data = $request->validate([
            'format' => ['required', 'string', 'in:'.implode(',', ExportService::FORMATS)],
            'include_trash' => ['nullable', 'boolean'],
        ]);
        $export = $exports->request($request->user(), $data['format'], (bool) ($data['include_trash'] ?? false));

        return response()->json(['data' => $this->present($export->refresh())], 202);
    }

    public function show(Request $request, DataExport $export): JsonResponse
    {
        Gate::authorize('view', $export);

        return response()->json(['data' => $this->present($export)]);
    }

    public function download(Request $request, DataExport $export): StreamedResponse
    {
        Gate::authorize('view', $export);
        abort_if($export->status !== 'ready' || ! $export->storage_key, 404);
        abort_if($export->isExpired(), 410, 'This export has expired.');

        return Storage::disk((string) $export->disk)->download((string) $export->storage_key, (string) $export->filename, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed> */
    private function present(DataExport $export): array
    {
        $data = DataTransferController::exportJson($export);
        $data['download_url'] = $data['download_url'] ? route('api.v1.exports.download', $export) : null;

        return $data;
    }
}

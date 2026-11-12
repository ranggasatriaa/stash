<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\DataTransferController;
use App\Stash\Models\DataImport;
use App\Stash\Transfer\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ImportController extends Controller
{
    public function __construct(private readonly ImportService $imports) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);
        $import = $this->imports->upload($request->user(), $request->file('file'));

        return response()->json(['data' => DataTransferController::importJson($import->refresh())], 202);
    }

    public function show(Request $request, DataImport $import): JsonResponse
    {
        Gate::authorize('view', $import);

        return response()->json(['data' => DataTransferController::importJson($import)]);
    }

    public function apply(Request $request, DataImport $import): JsonResponse
    {
        Gate::authorize('update', $import);
        $data = $request->validate(['collision_strategy' => ['required', 'string', 'in:'.implode(',', ImportService::STRATEGIES)]]);
        $import = $this->imports->confirm($request->user(), $import, $data['collision_strategy']);

        return response()->json(['data' => DataTransferController::importJson($import)], 202);
    }

    public function destroy(Request $request, DataImport $import): JsonResponse
    {
        Gate::authorize('delete', $import);
        $this->imports->discard($request->user(), $import);

        return response()->json(['data' => ['id' => $import->id, 'deleted' => true]]);
    }
}

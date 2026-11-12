<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiRunJson;
use App\Stash\Ai\SummaryService;
use App\Stash\Models\AiRun;
use App\Stash\Models\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AiRunController extends Controller
{
    /**
     * POST /resources/{id}/summaries. With `preview_only: true` it returns
     * the exact text that would be sent and the token estimate, spending
     * nothing. Otherwise it reserves budget and queues the run (202).
     */
    public function store(Request $request, Resource $resource, SummaryService $summaries): JsonResponse
    {
        Gate::authorize('update', $resource);
        $data = $request->validate([
            'source' => ['required', 'string', 'in:'.implode(',', SummaryService::SOURCES)],
            'text' => ['nullable', 'string', 'max:200000'],
            'confirm_metadata_only' => ['nullable', 'boolean'],
            'preview_only' => ['nullable', 'boolean'],
        ]);

        if (! empty($data['preview_only'])) {
            return response()->json(['data' => $summaries->preview($request->user(), $resource, $data['source'], $data['text'] ?? null)]);
        }

        $run = $summaries->start($request->user(), $resource, $data, $request->header('Idempotency-Key'));

        return response()->json(['data' => (new AiRunJson($run))->resolve($request)], 202);
    }

    public function show(Request $request, AiRun $aiRun): JsonResponse
    {
        Gate::authorize('view', $aiRun);

        return response()->json(['data' => (new AiRunJson($aiRun))->resolve($request)]);
    }
}

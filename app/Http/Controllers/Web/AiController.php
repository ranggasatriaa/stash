<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiRunJson;
use App\Stash\Ai\AiBudget;
use App\Stash\Ai\SummaryService;
use App\Stash\Models\AiRun;
use App\Stash\Models\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manual summary workflow for the web UI (JSON endpoints used by the detail
 * panel) and the AI settings page. Nothing here is required for normal use.
 */
class AiController extends Controller
{
    public function __construct(private readonly SummaryService $summaries) {}

    public function settings(Request $request, AiBudget $budget): Response
    {
        $user = $request->user();

        return Inertia::render('settings/ai', [
            'status' => $this->summaries->status(),
            'budget' => $budget->summary($user),
            'limits' => [
                'max_input_chars' => (int) config('stash.ai.max_input_chars'),
                'max_output_tokens' => (int) config('stash.ai.max_output_tokens'),
            ],
            'runs' => AiRun::query()->where('user_id', $user->id)->with('resource:id,title')->latest()->limit(25)->get()
                ->map(fn (AiRun $run) => (new AiRunJson($run))->resolve($request) + ['resource_title' => $run->resource?->title]),
        ]);
    }

    public function preview(Request $request, Resource $resource): JsonResponse
    {
        Gate::authorize('view', $resource);
        $data = $request->validate([
            'source' => ['required', 'string', 'in:'.implode(',', SummaryService::SOURCES)],
            'text' => ['nullable', 'string', 'max:200000'],
        ]);

        return response()->json(['data' => $this->summaries->preview($request->user(), $resource, $data['source'], $data['text'] ?? null)]);
    }

    public function store(Request $request, Resource $resource): JsonResponse
    {
        Gate::authorize('update', $resource);
        $data = $request->validate([
            'source' => ['required', 'string', 'in:'.implode(',', SummaryService::SOURCES)],
            'text' => ['nullable', 'string', 'max:200000'],
            'confirm_metadata_only' => ['nullable', 'boolean'],
        ]);

        $run = $this->summaries->start($request->user(), $resource, $data, $request->header('Idempotency-Key'));

        return response()->json(['data' => (new AiRunJson($run))->resolve($request)], 202);
    }

    public function show(Request $request, AiRun $aiRun): JsonResponse
    {
        Gate::authorize('view', $aiRun);

        return response()->json(['data' => (new AiRunJson($aiRun))->resolve($request)]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Stash\Resources\BulkActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\Exceptions\MissingAbilityException;

class BulkController extends Controller
{
    public function __invoke(Request $request, BulkActionService $service): JsonResponse
    {
        $data = $request->validate([
            'operation' => ['required', 'string', 'in:'.implode(',', BulkActionService::OPERATIONS)],
            'ids' => ['required', 'array', 'min:1', 'max:'.BulkActionService::MAX_IDS],
            'ids.*' => ['string', 'size:26'],
            'tags' => ['nullable', 'array', 'max:50'],
            'tags.*' => ['string', 'max:64'],
            'tag_ids' => ['nullable', 'array', 'max:50'],
            'tag_ids.*' => ['string', 'size:26'],
            'project_id' => ['nullable', 'string', 'size:26'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        if ($data['operation'] === 'delete_permanently') {
            if (! $request->user()->tokenCan('resources:delete')) {
                throw new MissingAbilityException(['resources:delete']);
            }
            $request->validate(['confirm' => ['required', 'accepted']]);
        }

        return response()->json(['data' => $service->run($request->user(), $data['operation'], $data['ids'], $data)]);
    }
}

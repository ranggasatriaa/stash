<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TagJson;
use App\Stash\Models\Tag;
use App\Stash\Resources\NavigationCounts;
use App\Stash\Taxonomy\TagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TagController extends Controller
{
    public function __construct(private readonly TagService $tags) {}

    public function index(Request $request, NavigationCounts $counts): JsonResponse
    {
        return response()->json(['data' => $counts->tags($request->user())]);
    }

    public function show(Request $request, Tag $tag): JsonResponse
    {
        Gate::authorize('view', $tag);

        return response()->json(['data' => (new TagJson($tag))->resolve($request)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:64'], 'color' => ['nullable', 'string', 'max:16']]);
        $tag = $this->tags->create($request->user(), $data['name'], $data['color'] ?? null);

        return response()->json(['data' => (new TagJson($tag))->resolve($request)], 201);
    }

    public function update(Request $request, Tag $tag): JsonResponse
    {
        Gate::authorize('update', $tag);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:64'], 'color' => ['sometimes', 'nullable', 'string', 'max:16']]);
        $tag = $this->tags->update($request->user(), $tag, $data['name'] ?? null, $data['color'] ?? null, array_key_exists('color', $data));

        return response()->json(['data' => (new TagJson($tag))->resolve($request)]);
    }

    public function destroy(Request $request, Tag $tag): JsonResponse
    {
        Gate::authorize('delete', $tag);
        $detached = $this->tags->delete($request->user(), $tag);

        return response()->json(['data' => ['id' => $tag->id, 'deleted' => true, 'detached_resources' => $detached]]);
    }
}

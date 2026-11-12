<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectJson;
use App\Stash\Models\Project;
use App\Stash\Resources\NavigationCounts;
use App\Stash\Taxonomy\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projects) {}

    public function index(Request $request, NavigationCounts $counts): JsonResponse
    {
        return response()->json(['data' => $counts->projects($request->user())]);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json(['data' => (new ProjectJson($project))->resolve($request)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $project = $this->projects->create($request->user(), $data);

        return response()->json(['data' => (new ProjectJson($project))->resolve($request)], 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);
        $project = $this->projects->update($request->user(), $project, $request->validate($this->rules(false)));

        return response()->json(['data' => (new ProjectJson($project))->resolve($request)]);
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('delete', $project);
        $unassigned = $this->projects->delete($request->user(), $project);

        return response()->json(['data' => ['id' => $project->id, 'deleted' => true, 'unassigned_resources' => $unassigned]]);
    }

    /** @return array<string, mixed> */
    private function rules(bool $required): array
    {
        return [
            'name' => [$required ? 'required' : 'sometimes', 'string', 'max:120', 'regex:/\S/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'color' => ['sometimes', 'nullable', 'string', 'max:16'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }
}

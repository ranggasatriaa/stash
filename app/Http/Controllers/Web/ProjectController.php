<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Stash\Models\Project;
use App\Stash\Resources\NavigationCounts;
use App\Stash\Taxonomy\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projects) {}

    public function index(Request $request, NavigationCounts $counts): Response
    {
        return Inertia::render('projects/index', ['projects' => $counts->projects($request->user())]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(required: true));
        $this->projects->create($request->user(), $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Project created.']);

        return back();
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $data = $request->validate($this->rules(required: false));
        $this->projects->update($request->user(), $project, $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Project updated.']);

        return back();
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);
        $unassigned = $this->projects->delete($request->user(), $project);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Project deleted. {$unassigned} resource".($unassigned === 1 ? ' was' : 's were').' unassigned and kept.']);

        return $request->header('X-Stash-Return') === 'library' ? redirect()->route('library') : back();
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

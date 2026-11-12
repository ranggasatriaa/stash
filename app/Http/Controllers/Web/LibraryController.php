<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiRunJson;
use App\Http\Resources\ResourceJson;
use App\Models\User;
use App\Stash\Enums\AiRunStatus;
use App\Stash\Enums\Section;
use App\Stash\Models\AiRun;
use App\Stash\Models\Project;
use App\Stash\Models\Resource;
use App\Stash\Models\Tag;
use App\Stash\Resources\ResourceFilters;
use App\Stash\Resources\ResourceQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LibraryController extends Controller
{
    public function __construct(private readonly ResourceQuery $query) {}

    public function index(Request $request): Response
    {
        return $this->render($request, Section::All);
    }

    public function section(Request $request, string $section): Response
    {
        return $this->render($request, Section::from($section));
    }

    public function project(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        return $this->render($request, Section::All, project: $project);
    }

    public function tag(Request $request, Tag $tag): Response
    {
        Gate::authorize('view', $tag);

        return $this->render($request, Section::All, tag: $tag);
    }

    private function render(Request $request, Section $section, ?Project $project = null, ?Tag $tag = null): Response
    {
        /** @var User $user */
        $user = $request->user();
        $input = $request->query();
        $input['per_page'] ??= $user->preference('page_size', config('stash.pagination.default'));
        if (! isset($input['sort']) && ($input['q'] ?? '') === '' && $user->preference('default_sort')) {
            $input['sort'] = $user->preference('default_sort');
        }

        $filters = ResourceFilters::fromArray($input, $section, $project?->id, $tag?->id);
        $page = $this->query->paginate($user, $filters);

        $scope = null;
        if ($project) {
            $scope = ['type' => 'project', 'id' => $project->id, 'name' => $project->name, 'description' => $project->description, 'color' => $project->color];
        } elseif ($tag) {
            $scope = ['type' => 'tag', 'id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color];
        }

        return Inertia::render('library/index', [
            'section' => $section->value,
            'scope' => $scope,
            'filters' => $filters->toArray() + ['sort_effective' => $filters->effectiveSort()->value],
            'resources' => [
                'data' => ResourceJson::collection($page->items())->resolve($request),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'from' => $page->firstItem(),
                    'to' => $page->lastItem(),
                ],
            ],
            'selected' => fn () => $this->selected($request, $user),
            'layout' => in_array($user->preference('layout'), ['grid', 'table'], true) ? $user->preference('layout') : 'grid',
        ]);
    }

    /** @return array<string, mixed>|null */
    private function selected(Request $request, User $user): ?array
    {
        $id = $request->query('resource');
        if (! ResourceFilters::isId($id)) {
            return null;
        }

        $resource = Resource::withTrashed()->where('user_id', $user->id)->whereKey(strtolower((string) $id))
            ->with(['tags:id,name,normalized_name,color', 'project:id,name,color,icon', 'files'])->first();
        if (! $resource) {
            return null;
        }

        $data = (new ResourceJson($resource))->withDetail()->resolve($request);

        $summary = AiRun::query()->where('user_id', $user->id)->where('resource_id', $resource->id)
            ->where('status', AiRunStatus::Succeeded->value)->whereNotNull('result')->latest()->first();
        $active = AiRun::query()->where('user_id', $user->id)->where('resource_id', $resource->id)
            ->whereIn('status', [AiRunStatus::Queued->value, AiRunStatus::Running->value])->latest()->first();
        $lastFailed = AiRun::query()->where('user_id', $user->id)->where('resource_id', $resource->id)->latest()->first();

        $data['summary'] = $summary ? (new AiRunJson($summary))->resolve($request) : null;
        $data['active_run'] = $active ? (new AiRunJson($active))->resolve($request) : null;
        $data['last_run'] = $lastFailed ? (new AiRunJson($lastFailed))->resolve($request) : null;

        return $data;
    }
}

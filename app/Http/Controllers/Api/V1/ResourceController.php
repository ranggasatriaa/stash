<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResourceRequest;
use App\Http\Requests\UpdateResourceRequest;
use App\Http\Resources\ResourceJson;
use App\Stash\Enums\Section;
use App\Stash\Exceptions\StashException;
use App\Stash\Models\Resource;
use App\Stash\Resources\DuplicateDetector;
use App\Stash\Resources\ResourceFilters;
use App\Stash\Resources\ResourceQuery;
use App\Stash\Resources\ResourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ResourceController extends Controller
{
    public function __construct(private readonly ResourceService $service) {}

    public function index(Request $request, ResourceQuery $query): JsonResponse
    {
        $data = $request->validate([
            'section' => ['nullable', 'string', 'in:'.implode(',', Section::values())],
            'q' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable'],
            'tags' => ['nullable'],
            'tag_mode' => ['nullable', 'string', 'in:any,all'],
            'project' => ['nullable', 'string'],
            'reading' => ['nullable', 'string', 'in:unread,read'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', 'string', 'in:relevance,newest,oldest,title'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('stash.pagination.max')],
        ]);

        $filters = ResourceFilters::fromArray($request->query(), Section::from($data['section'] ?? 'all'));
        $page = $query->paginate($request->user(), $filters);

        return response()->json([
            'data' => ResourceJson::collection($page->items())->resolve($request),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'sort' => $filters->effectiveSort()->value,
            ],
        ]);
    }

    public function duplicates(Request $request, DuplicateDetector $detector): JsonResponse
    {
        $data = $request->validate(['url' => ['nullable', 'string', 'max:4096'], 'sha256' => ['nullable', 'string', 'size:64']]);
        $matches = ! empty($data['url'])
            ? $detector->forUrl($request->user(), $data['url'])
            : (! empty($data['sha256']) ? $detector->forChecksum($request->user(), strtolower($data['sha256'])) : []);

        return response()->json(['data' => $matches]);
    }

    public function store(StoreResourceRequest $request): JsonResponse
    {
        $result = $request->hasFile('file')
            ? $this->service->createFromUpload($request->user(), $request->file('file'), $request->payload())
            : $this->service->createFromUrl($request->user(), $request->payload());

        return response()->json([
            'data' => (new ResourceJson($result['resource']))->withDetail()->resolve($request),
            'meta' => ['duplicates' => $result['duplicates']],
        ], 201);
    }

    public function show(Request $request, Resource $resource): JsonResponse
    {
        Gate::authorize('view', $resource);

        return response()->json(['data' => (new ResourceJson($this->service->reload($resource)))->withDetail()->resolve($request)]);
    }

    public function update(UpdateResourceRequest $request, Resource $resource): JsonResponse
    {
        Gate::authorize('update', $resource);
        $version = $request->expectedVersion() ?? $this->ifMatch($request);
        if ($version === null) {
            throw new StashException('precondition_required', 'Send the current "version" (or an If-Match header) to update a resource.', 428);
        }

        $updated = $this->service->update($request->user(), $resource, $request->changes(), $version);

        return $this->json($request, $updated);
    }

    public function archive(Request $request, Resource $resource): JsonResponse
    {
        Gate::authorize('update', $resource);

        return $this->json($request, $this->service->archive($request->user(), $resource, $this->version($request)));
    }

    public function restoreArchive(Request $request, Resource $resource): JsonResponse
    {
        Gate::authorize('update', $resource);

        return $this->json($request, $this->service->unarchive($request->user(), $resource, $this->version($request)));
    }

    public function trash(Request $request, Resource $resource): JsonResponse
    {
        Gate::authorize('delete', $resource);

        return $this->json($request, $this->service->trash($request->user(), $resource, $this->version($request)));
    }

    public function restore(Request $request, Resource $resource): JsonResponse
    {
        Gate::authorize('restore', $resource);

        return $this->json($request, $this->service->restore($request->user(), $resource, $this->version($request)));
    }

    public function destroy(Request $request, Resource $resource): JsonResponse
    {
        Gate::authorize('forceDelete', $resource);
        $request->validate(['confirm' => ['required', 'accepted']]);
        $this->service->forceDelete($request->user(), $resource);

        return response()->json(['data' => ['id' => $resource->id, 'deleted' => true]]);
    }

    public function refresh(Request $request, Resource $resource): JsonResponse
    {
        Gate::authorize('update', $resource);

        return $this->json($request, $this->service->refresh($request->user(), $resource), 202);
    }

    private function json(Request $request, Resource $resource, int $status = 200): JsonResponse
    {
        return response()->json(['data' => (new ResourceJson($resource))->withDetail()->resolve($request)], $status)
            ->header('ETag', 'W/"'.$resource->version.'"');
    }

    private function version(Request $request): ?int
    {
        $v = $request->input('version');

        return is_numeric($v) ? (int) $v : $this->ifMatch($request);
    }

    private function ifMatch(Request $request): ?int
    {
        $header = (string) $request->header('If-Match', '');
        if (preg_match('/^(?:W\/)?"?(\d+)"?$/', trim($header), $m)) {
            return (int) $m[1];
        }

        return null;
    }
}

<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResourceRequest;
use App\Http\Requests\UpdateResourceRequest;
use App\Stash\Models\Resource;
use App\Stash\Resources\DuplicateDetector;
use App\Stash\Resources\ResourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Inertia write endpoints. All behavior lives in ResourceService (shared
 * with /api/v1); controllers only authorize, validate and respond.
 */
class ResourceController extends Controller
{
    public function __construct(private readonly ResourceService $service) {}

    public function store(StoreResourceRequest $request): RedirectResponse
    {
        $user = $request->user();
        $result = $request->hasFile('file')
            ? $this->service->createFromUpload($user, $request->file('file'), $request->payload())
            : $this->service->createFromUrl($user, $request->payload());

        $resource = $result['resource'];
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $result['duplicates'] ? 'Saved (you already had this one).' : 'Resource saved.',
            'undo' => ['method' => 'delete', 'url' => route('resources.trash', $resource), 'label' => 'Undo'],
        ]);
        Inertia::flash('saved', ['id' => $resource->id, 'title' => $resource->title, 'duplicates' => $result['duplicates']]);

        return back();
    }

    public function duplicates(Request $request, DuplicateDetector $detector): JsonResponse
    {
        $data = $request->validate([
            'url' => ['nullable', 'string', 'max:4096'],
            'sha256' => ['nullable', 'string', 'size:64'],
            'except' => ['nullable', 'string', 'size:26'],
        ]);

        $matches = [];
        if (! empty($data['url'])) {
            $matches = $detector->forUrl($request->user(), $data['url'], $data['except'] ?? null);
        } elseif (! empty($data['sha256'])) {
            $matches = $detector->forChecksum($request->user(), strtolower($data['sha256']), $data['except'] ?? null);
        }

        return response()->json(['duplicates' => $matches]);
    }

    public function update(UpdateResourceRequest $request, Resource $resource): RedirectResponse
    {
        Gate::authorize('update', $resource);
        $this->service->update($request->user(), $resource, $request->changes(), $request->expectedVersion());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Changes saved.']);

        return back();
    }

    public function archive(Request $request, Resource $resource): RedirectResponse
    {
        Gate::authorize('update', $resource);
        $this->service->archive($request->user(), $resource, $this->version($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Archived.', 'undo' => ['method' => 'post', 'url' => route('resources.unarchive', $resource)]]);

        return back();
    }

    public function unarchive(Request $request, Resource $resource): RedirectResponse
    {
        Gate::authorize('update', $resource);
        $this->service->unarchive($request->user(), $resource, $this->version($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Restored from Archive.', 'undo' => ['method' => 'post', 'url' => route('resources.archive', $resource)]]);

        return back();
    }

    public function trash(Request $request, Resource $resource): RedirectResponse
    {
        Gate::authorize('delete', $resource);
        $this->service->trash($request->user(), $resource, $this->version($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Moved to Trash.', 'undo' => ['method' => 'post', 'url' => route('resources.restore', $resource)]]);

        return back();
    }

    public function restore(Request $request, Resource $resource): RedirectResponse
    {
        Gate::authorize('restore', $resource);
        $this->service->restore($request->user(), $resource, $this->version($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Restored from Trash.', 'undo' => ['method' => 'delete', 'url' => route('resources.trash', $resource)]]);

        return back();
    }

    public function destroy(Request $request, Resource $resource): RedirectResponse
    {
        Gate::authorize('forceDelete', $resource);
        $request->validate(['confirm' => ['required', 'accepted']], ['confirm.accepted' => 'Confirm permanent deletion.']);
        $this->service->forceDelete($request->user(), $resource);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Deleted permanently.']);

        return redirect()->to($this->withoutSelection((string) url()->previous()));
    }

    public function refresh(Request $request, Resource $resource): RedirectResponse
    {
        Gate::authorize('update', $resource);
        $this->service->refresh($request->user(), $resource);
        Inertia::flash('toast', ['type' => 'info', 'message' => 'Refresh queued.']);

        return back();
    }

    public function applySource(Request $request, Resource $resource): RedirectResponse
    {
        Gate::authorize('update', $resource);
        $data = $request->validate([
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', 'in:title,description'],
            'version' => ['nullable', 'integer'],
        ]);
        $this->service->applySourceValues($request->user(), $resource, $data['fields'], isset($data['version']) ? (int) $data['version'] : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Replaced with source values.']);

        return back();
    }

    private function version(Request $request): ?int
    {
        $v = $request->input('version');

        return is_numeric($v) ? (int) $v : null;
    }

    private function withoutSelection(string $url): string
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        unset($query['resource']);
        $path = $parts['path'] ?? '/library';

        return $path.($query ? '?'.http_build_query($query) : '');
    }
}

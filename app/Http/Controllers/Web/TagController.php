<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Stash\Models\Tag;
use App\Stash\Resources\NavigationCounts;
use App\Stash\Taxonomy\TagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    public function __construct(private readonly TagService $tags) {}

    public function index(Request $request, NavigationCounts $counts): Response
    {
        return Inertia::render('tags/index', ['tags' => $counts->tags($request->user())]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:64'], 'color' => ['nullable', 'string', 'max:16']]);
        $this->tags->create($request->user(), $data['name'], $data['color'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tag created.']);

        return back();
    }

    public function update(Request $request, Tag $tag): RedirectResponse
    {
        Gate::authorize('update', $tag);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:64'], 'color' => ['sometimes', 'nullable', 'string', 'max:16']]);
        $this->tags->update($request->user(), $tag, $data['name'] ?? null, $data['color'] ?? null, array_key_exists('color', $data));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tag updated.']);

        return back();
    }

    public function destroy(Request $request, Tag $tag): RedirectResponse
    {
        Gate::authorize('delete', $tag);
        $detached = $this->tags->delete($request->user(), $tag);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Tag deleted and removed from {$detached} resource".($detached === 1 ? '' : 's').'. The resources were kept.']);

        return $request->header('X-Stash-Return') === 'library' ? redirect()->route('library') : back();
    }
}

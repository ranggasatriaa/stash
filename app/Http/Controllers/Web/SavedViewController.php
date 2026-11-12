<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Stash\Enums\Section;
use App\Stash\Models\SavedView;
use App\Stash\Resources\ResourceFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class SavedViewController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'regex:/\S/'],
            'section' => ['required', 'string', 'in:'.implode(',', Section::values())],
            'filters' => ['nullable', 'array'],
        ]);

        // Re-parse through the same normalizer so only valid filter keys persist.
        $filters = ResourceFilters::fromArray((array) ($data['filters'] ?? []), Section::from($data['section']))->toArray();
        unset($filters['per_page']);

        $view = new SavedView([
            'name' => trim($data['name']),
            'section' => $data['section'],
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== []),
            'sort' => $filters['sort'] ?? 'newest',
            'position' => (int) SavedView::query()->where('user_id', $request->user()->id)->max('position') + 1,
        ]);
        $view->user_id = $request->user()->id;
        $view->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'View saved.']);

        return back();
    }

    public function destroy(Request $request, SavedView $savedView): RedirectResponse
    {
        Gate::authorize('delete', $savedView);
        $savedView->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved view removed.']);

        return back();
    }
}

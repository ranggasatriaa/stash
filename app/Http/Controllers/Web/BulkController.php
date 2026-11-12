<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Stash\Resources\BulkActionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BulkController extends Controller
{
    private const LABELS = [
        'add_tags' => 'Tags added', 'remove_tags' => 'Tags removed', 'set_project' => 'Project updated',
        'mark_read' => 'Marked read', 'mark_unread' => 'Marked unread', 'read_later_on' => 'Added to Read later',
        'read_later_off' => 'Removed from Read later', 'archive' => 'Archived', 'unarchive' => 'Restored from Archive',
        'trash' => 'Moved to Trash', 'restore' => 'Restored from Trash', 'delete_permanently' => 'Deleted permanently',
    ];

    public function __invoke(Request $request, BulkActionService $service): RedirectResponse
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

        if ($data['operation'] === 'delete_permanently' && empty($data['confirm'])) {
            return back()->withErrors(['confirm' => 'Confirm permanent deletion.']);
        }

        $result = $service->run($request->user(), $data['operation'], $data['ids'], $data);

        $changed = count($result['changed']);
        $skipped = count($result['skipped']);
        $message = self::LABELS[$data['operation']].': '.$changed.' item'.($changed === 1 ? '' : 's');
        if ($skipped > 0) {
            $reasons = collect($result['skipped'])->countBy('reason')->map(fn ($n, $r) => $n.' '.str_replace('_', ' ', $r))->implode(', ');
            $message .= " · skipped {$skipped} ({$reasons})";
        }

        Inertia::flash('toast', [
            'type' => $changed > 0 ? 'success' : 'info',
            'message' => $message,
            'undo' => $result['undo'] ? ['method' => 'post', 'url' => route('resources.bulk'), 'data' => $result['undo']] : null,
        ]);
        Inertia::flash('bulk', $result);

        return back();
    }
}

<?php

namespace App\Stash\Resources;

use App\Models\User;
use App\Stash\Models\Resource;
use App\Stash\Support\UrlNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Owner-scoped duplicate warnings. Never blocks a save and never looks at
 * another owner's data.
 */
final class DuplicateDetector
{
    /** @return list<array{id: string, title: string, type: string, created_at: string, archived: bool, trashed: bool}> */
    public function forUrl(User $user, string $url, ?string $exceptId = null): array
    {
        if (! UrlNormalizer::isValidHttpUrl($url)) {
            return [];
        }

        $hash = UrlNormalizer::normalize($url)['hash'];
        $query = Resource::withTrashed()
            ->where('user_id', $user->id)
            ->where('normalized_url_hash', $hash)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->orderByDesc('created_at')
            ->limit(5);

        return $this->present($query->get(['id', 'title', 'type', 'created_at', 'archived_at', 'deleted_at']));
    }

    /** @return list<array{id: string, title: string, type: string, created_at: string, archived: bool, trashed: bool}> */
    public function forChecksum(User $user, string $sha256, ?string $exceptId = null): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            return [];
        }

        $ids = DB::table('resource_files')
            ->where('user_id', $user->id)
            ->where('role', 'original')
            ->where('checksum_sha256', $sha256)
            ->when($exceptId, fn ($q) => $q->where('resource_id', '!=', $exceptId))
            ->limit(5)
            ->pluck('resource_id');

        $resources = Resource::withTrashed()->where('user_id', $user->id)->whereIn('id', $ids)
            ->orderByDesc('created_at')->get(['id', 'title', 'type', 'created_at', 'archived_at', 'deleted_at']);

        return $this->present($resources);
    }

    /**
     * @param  iterable<Resource>  $resources
     * @return list<array{id: string, title: string, type: string, created_at: string, archived: bool, trashed: bool}>
     */
    private function present(iterable $resources): array
    {
        $out = [];
        foreach ($resources as $r) {
            $out[] = [
                'id' => $r->id,
                'title' => $r->title,
                'type' => $r->type->value,
                'created_at' => $r->created_at->toIso8601ZuluString(),
                'archived' => $r->archived_at !== null,
                'trashed' => $r->deleted_at !== null,
            ];
        }

        return $out;
    }
}

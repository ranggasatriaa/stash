<?php

namespace App\Http\Resources;

use App\Stash\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tag */
class TagJson extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'color' => $this->resource->color,
            'count' => $this->resource->getAttribute('active_count') !== null ? (int) $this->resource->getAttribute('active_count') : null,
            'total' => $this->resource->getAttribute('total_count') !== null ? (int) $this->resource->getAttribute('total_count') : null,
            'created_at' => $this->resource->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->resource->updated_at?->toIso8601ZuluString(),
        ];
    }
}

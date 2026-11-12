<?php

namespace App\Http\Resources;

use App\Stash\Models\AiRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiRun */
class AiRunJson extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AiRun $run */
        $run = $this->resource;

        return [
            'id' => $run->id,
            'resource_id' => $run->resource_id,
            'task' => $run->task,
            'status' => $run->status->value,
            'provider' => $run->provider,
            'model' => $run->model,
            'prompt_version' => $run->prompt_version,
            'source_kind' => $run->source_kind,
            'input_chars' => $run->input_chars,
            'cached' => $run->cached,
            'result' => $run->result,
            'usage' => [
                'reserved_tokens' => $run->reserved_tokens,
                'prompt_tokens' => $run->prompt_tokens,
                'completion_tokens' => $run->completion_tokens,
                'total_tokens' => $run->total_tokens,
                'estimated' => $run->usage_estimated,
            ],
            'error' => $run->error_code ? ['code' => $run->error_code, 'message' => $run->error_message] : null,
            'created_at' => $run->created_at->toIso8601ZuluString(),
            'finished_at' => $run->finished_at?->toIso8601ZuluString(),
        ];
    }
}

<?php

namespace App\Stash\Models;

use App\Stash\Enums\AiRunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $resource_id
 * @property string $task
 * @property string $provider
 * @property string $model
 * @property string $prompt_version
 * @property AiRunStatus $status
 * @property string $source_kind
 * @property string $input_hash
 * @property int $input_chars
 * @property string|null $input_text
 * @property int $reserved_tokens
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property int|null $total_tokens
 * @property bool $usage_estimated
 * @property bool $cached
 * @property array<string, mixed>|null $result
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $attempts
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 */
class AiRun extends Model
{
    use HasUlids;

    protected $fillable = [
        'resource_id', 'task', 'provider', 'model', 'prompt_version', 'status', 'source_kind',
        'input_hash', 'input_chars', 'input_text', 'reserved_tokens', 'prompt_tokens',
        'completion_tokens', 'total_tokens', 'usage_estimated', 'cached', 'result', 'error_code',
        'error_message', 'attempts', 'idempotency_key', 'started_at', 'finished_at',
    ];

    protected $hidden = ['input_text'];

    protected function casts(): array
    {
        return [
            'status' => AiRunStatus::class,
            'result' => 'array',
            'usage_estimated' => 'boolean',
            'cached' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'reserved_tokens' => 'integer',
            'total_tokens' => 'integer',
        ];
    }

    /** @return BelongsTo<Resource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class)->withTrashed();
    }
}

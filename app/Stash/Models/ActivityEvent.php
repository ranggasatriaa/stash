<?php

namespace App\Stash\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail entry. Only safe summaries (field names, counts, ids) are
 * stored, never content such as notes, article text or token material.
 *
 * @property int $id
 * @property int $user_id
 * @property string $actor_type user|token|job|cli
 * @property string|null $actor_id
 * @property string $operation
 * @property string|null $entity_type
 * @property string|null $entity_id
 * @property array<string, mixed> $changes
 * @property string|null $request_id
 */
class ActivityEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'actor_type', 'actor_id', 'operation', 'entity_type', 'entity_id', 'changes', 'request_id'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }
}

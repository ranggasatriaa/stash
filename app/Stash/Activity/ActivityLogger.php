<?php

namespace App\Stash\Activity;

use App\Stash\Models\ActivityEvent;
use App\Stash\Support\RequestContext;

/**
 * Records who did what. Only safe summaries are stored: operation names,
 * entity ids, changed field names and counts. Never content or secrets.
 */
final class ActivityLogger
{
    public function __construct(private readonly RequestContext $context) {}

    /** @param array<string, mixed> $changes */
    public function record(int $userId, string $operation, ?string $entityType = null, ?string $entityId = null, array $changes = []): void
    {
        ActivityEvent::query()->create([
            'user_id' => $userId,
            'actor_type' => $this->context->actorType,
            'actor_id' => $this->context->actorId,
            'operation' => $operation,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'changes' => $changes,
            'request_id' => $this->context->requestId,
        ]);
    }
}

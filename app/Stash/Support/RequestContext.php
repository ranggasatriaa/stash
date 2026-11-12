<?php

namespace App\Stash\Support;

/**
 * Per-request correlation data (request id + acting principal). Bound as a
 * scoped singleton so queued jobs and CLI commands get a fresh instance.
 */
final class RequestContext
{
    public ?string $requestId = null;

    /** user|token|job|cli */
    public string $actorType = 'user';

    public ?string $actorId = null;

    public function asJob(string $jobName): self
    {
        $this->actorType = 'job';
        $this->actorId = $jobName;

        return $this;
    }
}

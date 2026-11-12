<?php

namespace App\Stash\Ai;

use RuntimeException;

/**
 * Normalized provider failure.
 *
 * category: rate_limited | unavailable (safe to retry with backoff: the
 * provider explicitly did not process the request), timeout (ambiguous:
 * never auto-retried), auth, insufficient_balance, invalid_request,
 * refused, truncated, blank, malformed.
 */
final class ProviderError extends RuntimeException
{
    /** @param array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int}|null $usage */
    public function __construct(
        public readonly string $category,
        string $message,
        public readonly ?array $usage = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return in_array($this->category, ['rate_limited', 'unavailable'], true);
    }
}

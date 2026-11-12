<?php

namespace App\Stash\Ai;

final class SummaryResponse
{
    /**
     * @param  list<string>  $keyPoints
     * @param  array{prompt_tokens: int, completion_tokens: int, total_tokens: int}|null  $usage  null when the provider did not report usage
     */
    public function __construct(
        public readonly string $summary,
        public readonly array $keyPoints,
        public readonly ?array $usage,
        public readonly string $model,
    ) {}
}

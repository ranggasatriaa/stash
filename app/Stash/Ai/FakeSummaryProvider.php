<?php

namespace App\Stash\Ai;

/**
 * Deterministic provider for tests and demos. Never performs network calls.
 * Queue behaviours with `respondWith()` / `failWith()`.
 */
final class FakeSummaryProvider implements SummaryProvider
{
    /** @var list<SummaryResponse|ProviderError> */
    private array $queue = [];

    public int $calls = 0;

    public ?string $lastText = null;

    public function __construct(private readonly bool $configured = true, private readonly string $model = 'fake-model') {}

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function respondWith(SummaryResponse|ProviderError $outcome): self
    {
        $this->queue[] = $outcome;

        return $this;
    }

    /** @param array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int}|null $usage */
    public function failWith(string $category, string $message = 'Simulated failure', ?array $usage = null): self
    {
        return $this->respondWith(new ProviderError($category, $message, $usage));
    }

    public function summarize(string $text, string $sourceKind, int $maxOutputTokens): SummaryResponse
    {
        $this->calls++;
        $this->lastText = $text;

        $next = array_shift($this->queue);
        if ($next instanceof ProviderError) {
            throw $next;
        }
        if ($next instanceof SummaryResponse) {
            return $next;
        }

        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return new SummaryResponse(
            'Summary of '.count($words).' words: '.implode(' ', array_slice($words, 0, 12)),
            ['First point', 'Second point'],
            ['prompt_tokens' => 120, 'completion_tokens' => 40, 'total_tokens' => 160],
            $this->model,
        );
    }
}

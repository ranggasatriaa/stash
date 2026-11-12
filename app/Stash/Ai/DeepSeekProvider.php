<?php

namespace App\Stash\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * DeepSeek chat-completions provider (OpenAI-compatible API).
 * Verified against https://api-docs.deepseek.com on 2026-11-12:
 * POST {base}/chat/completions, JSON mode via response_format json_object
 * (prompt must mention "json"), non-thinking mode via thinking.disabled,
 * usage.{prompt,completion,total}_tokens, finish_reason stop|length|
 * content_filter|insufficient_system_resource, errors 400/401/402/422/429/
 * 500/503. The model id comes from DEEPSEEK_MODEL; nothing is hardcoded.
 */
final class DeepSeekProvider implements SummaryProvider
{
    public function name(): string
    {
        return 'deepseek';
    }

    public function model(): string
    {
        return (string) config('services.deepseek.model');
    }

    public function isConfigured(): bool
    {
        return (string) config('services.deepseek.api_key') !== '' && $this->model() !== '';
    }

    public function summarize(string $text, string $sourceKind, int $maxOutputTokens): SummaryResponse
    {
        $payload = [
            'model' => $this->model(),
            'messages' => [
                ['role' => 'system', 'content' => SummaryOutput::systemPrompt()],
                ['role' => 'user', 'content' => SummaryOutput::userPrompt($text, $sourceKind)],
            ],
            'response_format' => ['type' => 'json_object'],
            'thinking' => ['type' => 'disabled'],
            'max_tokens' => $maxOutputTokens,
            'temperature' => 0.3,
            'stream' => false,
        ];

        try {
            $response = Http::baseUrl(rtrim((string) config('services.deepseek.base_url', 'https://api.deepseek.com'), '/'))
                ->withToken((string) config('services.deepseek.api_key'))
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout((int) config('stash.ai.timeout', 60))
                ->post('/chat/completions', $payload);
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');
            // A timeout is ambiguous (the provider may have billed it): never auto-retry.
            throw new ProviderError($timedOut ? 'timeout' : 'unavailable', $timedOut ? 'The AI provider timed out.' : 'Could not connect to the AI provider.');
        } catch (Throwable) {
            throw new ProviderError('unavailable', 'Could not reach the AI provider.');
        }

        if (! $response->successful()) {
            throw $this->httpError($response);
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new ProviderError('malformed', 'The AI provider returned an unreadable response.');
        }

        $usage = $this->usage($json['usage'] ?? null);
        $choice = $json['choices'][0] ?? null;
        $finish = is_array($choice) ? ($choice['finish_reason'] ?? null) : null;
        $content = is_array($choice) ? ($choice['message']['content'] ?? null) : null;

        if ($finish === 'content_filter') {
            throw new ProviderError('refused', 'The AI provider declined to summarize this text.', $usage);
        }
        if ($finish === 'length') {
            throw new ProviderError('truncated', 'The summary was cut off by the output limit.', $usage);
        }
        if ($finish === 'insufficient_system_resource') {
            throw new ProviderError('unavailable', 'The AI provider is temporarily out of capacity.', $usage);
        }

        try {
            $parsed = SummaryOutput::parse(is_string($content) ? $content : null);
        } catch (ProviderError $e) {
            throw new ProviderError($e->category, $e->getMessage(), $usage);
        }

        return new SummaryResponse($parsed['summary'], $parsed['key_points'], $usage, (string) ($json['model'] ?? $this->model()));
    }

    private function httpError(Response $response): ProviderError
    {
        $retryAfter = (int) $response->header('Retry-After') ?: null;

        return match ($response->status()) {
            401, 403 => new ProviderError('auth', 'The AI provider rejected the API key.'),
            402 => new ProviderError('insufficient_balance', 'The AI provider account has insufficient balance.'),
            400, 422 => new ProviderError('invalid_request', 'The AI provider rejected the request parameters (check DEEPSEEK_MODEL).'),
            429 => new ProviderError('rate_limited', 'The AI provider rate limit was reached.', null, $retryAfter),
            500, 502, 503, 504 => new ProviderError('unavailable', 'The AI provider is temporarily unavailable.', null, $retryAfter),
            default => new ProviderError('unavailable', 'The AI provider returned an unexpected error.'),
        };
    }

    /** @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}|null */
    private function usage(mixed $usage): ?array
    {
        if (! is_array($usage) || ! isset($usage['total_tokens'])) {
            return null;
        }

        return [
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'total_tokens' => (int) $usage['total_tokens'],
        ];
    }
}

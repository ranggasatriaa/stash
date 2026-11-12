<?php

namespace App\Stash\Ai;

use App\Models\User;
use App\Stash\Activity\ActivityLogger;
use App\Stash\Enums\AiRunStatus;
use App\Stash\Exceptions\StashException;
use App\Stash\Fetch\FetchFailedException;
use App\Stash\Fetch\SafeFetcher;
use App\Stash\Fetch\UnsafeUrlException;
use App\Stash\Jobs\GenerateSummary;
use App\Stash\Metadata\ArticleTextExtractor;
use App\Stash\Models\AiRun;
use App\Stash\Models\Resource;
use Illuminate\Support\Facades\Log;

/**
 * Manually triggered article summaries. The user always sees exactly what
 * will be sent (preview), metadata-only summaries require explicit
 * acknowledgement, and images / personal notes are never sent.
 */
final class SummaryService
{
    public const SOURCES = ['article', 'pasted', 'metadata'];

    public function __construct(
        private readonly SummaryProvider $provider,
        private readonly AiBudget $budget,
        private readonly SafeFetcher $fetcher,
        private readonly ArticleTextExtractor $extractor,
        private readonly ActivityLogger $activity,
    ) {}

    /** @return array{enabled: bool, configured: bool, reason: ?string, provider: string, model: ?string} */
    public function status(): array
    {
        $enabled = (bool) config('stash.ai.enabled');
        $configured = $this->provider->isConfigured();
        $reason = match (true) {
            ! $enabled => 'AI summaries are turned off (AI_ENABLED=false).',
            ! $configured => 'AI summaries need DEEPSEEK_API_KEY and DEEPSEEK_MODEL on the server.',
            default => null,
        };

        return [
            'enabled' => $enabled && $configured,
            'configured' => $configured,
            'reason' => $reason,
            'provider' => $this->provider->name(),
            'model' => $configured ? $this->provider->model() : null,
        ];
    }

    public function assertAvailable(): void
    {
        $status = $this->status();
        if (! $status['enabled']) {
            throw new StashException('ai_disabled', (string) $status['reason'], 503);
        }
    }

    /**
     * Build the exact text that would be sent, without spending anything.
     *
     * @return array{source: string, text: string, chars: int, truncated: bool, estimated_tokens: int, limitation: ?string, budget: array<string, mixed>}
     */
    public function preview(User $user, Resource $resource, string $source, ?string $pasted = null): array
    {
        $this->assertAvailable();
        $this->assertArticle($resource);

        [$text, $truncated, $limitation] = $this->buildInput($resource, $source, $pasted);
        $max = (int) config('stash.ai.max_output_tokens', 800);

        return [
            'source' => $source,
            'text' => $text,
            'chars' => mb_strlen($text),
            'truncated' => $truncated,
            'estimated_tokens' => SummaryOutput::estimateTokens($text, $max),
            'limitation' => $limitation,
            'budget' => $this->budget->summary($user),
        ];
    }

    /**
     * Reserve budget and queue a summary run (or serve it from cache).
     *
     * @param  array{source: string, text?: ?string, confirm_metadata_only?: bool}  $input
     */
    public function start(User $user, Resource $resource, array $input, ?string $idempotencyKey = null): AiRun
    {
        $this->assertAvailable();
        $this->assertArticle($resource);

        $source = (string) $input['source'];
        if (! in_array($source, self::SOURCES, true)) {
            throw new StashException('invalid_source', 'Choose article, pasted or metadata as the summary source.', 422, ['source' => ['Invalid source.']]);
        }
        if ($source === 'metadata' && empty($input['confirm_metadata_only'])) {
            throw new StashException('metadata_ack_required', 'Only metadata is available. Confirm that a metadata-only summary is acceptable.', 422, ['confirm_metadata_only' => ['Confirm the metadata-only limitation.']]);
        }

        $provided = isset($input['text']) && trim($input['text']) !== '' ? $input['text'] : null;
        if ($source === 'article' && $provided !== null) {
            // The client confirmed the previewed extraction; send exactly that text.
            [$text] = $this->bound($provided);
        } else {
            [$text] = $this->buildInput($resource, $source, $provided);
        }

        $model = $this->provider->model();
        $promptVersion = SummaryOutput::PROMPT_VERSION;
        $hash = hash('sha256', $text);

        $cached = AiRun::query()->where('user_id', $user->id)->where('input_hash', $hash)->where('model', $model)
            ->where('prompt_version', $promptVersion)->where('status', AiRunStatus::Succeeded->value)
            ->whereNotNull('result')->latest()->first();

        if ($cached) {
            $run = new AiRun([
                'resource_id' => $resource->id, 'task' => 'summary', 'provider' => $this->provider->name(),
                'model' => $model, 'prompt_version' => $promptVersion, 'status' => AiRunStatus::Succeeded,
                'source_kind' => $source, 'input_hash' => $hash, 'input_chars' => mb_strlen($text),
                'reserved_tokens' => 0, 'total_tokens' => 0, 'cached' => true, 'result' => $cached->result,
                'idempotency_key' => $idempotencyKey, 'finished_at' => now(),
            ]);
            $run->user_id = $user->id;
            $run->save();
            $this->activity->record($user->id, 'ai.summary.cached', 'resource', $resource->id, ['run' => $run->id]);

            return $run;
        }

        $estimate = SummaryOutput::estimateTokens($text, (int) config('stash.ai.max_output_tokens', 800));

        $run = $this->budget->reserve($user, $estimate, function () use ($user, $resource, $model, $promptVersion, $source, $hash, $text, $estimate, $idempotencyKey) {
            $run = new AiRun([
                'resource_id' => $resource->id, 'task' => 'summary', 'provider' => $this->provider->name(),
                'model' => $model, 'prompt_version' => $promptVersion, 'status' => AiRunStatus::Queued,
                'source_kind' => $source, 'input_hash' => $hash, 'input_chars' => mb_strlen($text),
                'input_text' => $text, 'reserved_tokens' => $estimate, 'idempotency_key' => $idempotencyKey,
            ]);
            $run->user_id = $user->id;
            $run->save();
            GenerateSummary::dispatch($run->id);

            return $run;
        });

        $this->activity->record($user->id, 'ai.summary.requested', 'resource', $resource->id, ['run' => $run->id, 'reserved' => $estimate]);

        return $run;
    }

    /** Called by the queued job. Never throws for provider errors. */
    public function execute(AiRun $run): ?int
    {
        $text = (string) $run->input_text;
        $run->status = AiRunStatus::Running;
        $run->attempts = $run->attempts + 1;
        $run->started_at ??= now();
        $run->save();

        try {
            $response = $this->provider->summarize($text, $run->source_kind, (int) config('stash.ai.max_output_tokens', 800));
        } catch (ProviderError $e) {
            $maxAttempts = (int) config('stash.ai.max_attempts', 3);
            if ($e->isRetryable() && $run->attempts < $maxAttempts) {
                $run->status = AiRunStatus::Queued;
                $run->error_code = $e->category;
                $run->error_message = $e->getMessage();
                $run->save();

                return $e->retryAfter ?? (int) (15 * 2 ** ($run->attempts - 1));
            }

            $this->finishFailed($run, $e);

            return null;
        }

        $run->status = AiRunStatus::Succeeded;
        $run->result = ['summary' => $response->summary, 'key_points' => $response->keyPoints];
        $this->applyUsage($run, $response->usage);
        $run->error_code = null;
        $run->error_message = null;
        $run->input_text = null;
        $run->finished_at = now();
        $run->save();

        return null;
    }

    /** @param array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int}|null $usage */
    private function applyUsage(AiRun $run, ?array $usage): void
    {
        if ($usage !== null) {
            $run->prompt_tokens = (int) ($usage['prompt_tokens'] ?? 0);
            $run->completion_tokens = (int) ($usage['completion_tokens'] ?? 0);
            $run->total_tokens = (int) ($usage['total_tokens'] ?? 0);
            $run->usage_estimated = false;
        } else {
            // Unknown usage: retain the reservation estimate.
            $run->total_tokens = $run->reserved_tokens;
            $run->usage_estimated = true;
        }
    }

    public function finishFailed(AiRun $run, ProviderError $e): void
    {
        $run->status = AiRunStatus::Failed;
        $run->error_code = $e->category;
        $run->error_message = mb_substr($e->getMessage(), 0, 255);
        $run->finished_at = now();

        if ($e->usage !== null) {
            $this->applyUsage($run, $e->usage);
        } elseif ($e->category === 'timeout') {
            // Ambiguous: the provider may have processed (and billed) it.
            $run->total_tokens = $run->reserved_tokens;
            $run->usage_estimated = true;
        } else {
            $run->total_tokens = 0;
        }
        $run->save();

        Log::info('ai.summary_failed', ['run_id' => $run->id, 'category' => $e->category]);
    }

    /** @return array{0: string, 1: bool, 2: ?string} text, truncated, limitation */
    private function buildInput(Resource $resource, string $source, ?string $pasted): array
    {
        if ($source === 'pasted') {
            if ($pasted === null || mb_strlen(trim($pasted)) < 50) {
                throw new StashException('text_required', 'Paste at least 50 characters of article text.', 422, ['text' => ['Paste at least 50 characters of article text.']]);
            }
            [$text, $truncated] = $this->bound($pasted);

            return [$text, $truncated, null];
        }

        if ($source === 'metadata') {
            $parts = array_filter([
                'Title: '.$resource->title,
                $resource->description ? 'Description: '.$resource->description : null,
                ($resource->metadata['site_name'] ?? null) ? 'Site: '.$resource->metadata['site_name'] : null,
            ]);

            return [implode("\n", $parts), false, 'Only the title and description are available, so the summary describes metadata, not the article itself.'];
        }

        if (! $resource->original_url) {
            throw new StashException('no_source', 'This article has no source URL. Paste the text instead.', 422);
        }

        try {
            $page = $this->fetcher->fetch($resource->original_url, SafeFetcher::PROFILE_HTML);
        } catch (UnsafeUrlException) {
            throw new StashException('article_unavailable', 'This address cannot be fetched automatically. Paste the article text instead.', 422);
        } catch (FetchFailedException) {
            throw new StashException('article_unavailable', 'The article could not be fetched. Paste the text instead, or use a metadata-only summary.', 422);
        }

        $max = (int) config('stash.ai.max_input_chars', 24000);
        $text = $this->extractor->extract($page->body, $page->charset, $max + 1);
        if (mb_strlen($text) < 200) {
            throw new StashException('article_unavailable', 'Not enough readable text was found (the page may need JavaScript). Paste the text instead, or use a metadata-only summary.', 422);
        }
        [$text, $truncated] = $this->bound($text);

        return [$text, $truncated || $page->truncated, null];
    }

    /** @return array{0: string, 1: bool} */
    private function bound(string $text): array
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\r\n", "\n", $text)) ?? '';
        $text = trim($text);
        $max = (int) config('stash.ai.max_input_chars', 24000);
        if (mb_strlen($text) > $max) {
            return [mb_substr($text, 0, $max), true];
        }

        return [$text, false];
    }

    private function assertArticle(Resource $resource): void
    {
        if (! $resource->isArticle()) {
            throw new StashException('not_article', 'Summaries are available for articles only.', 422);
        }
        if ($resource->trashed()) {
            throw new StashException('in_trash', 'Restore this resource before summarizing it.', 409);
        }
    }
}

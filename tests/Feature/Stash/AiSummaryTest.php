<?php

namespace Tests\Feature\Stash;

use App\Models\User;
use App\Stash\Ai\DeepSeekProvider;
use App\Stash\Ai\FakeSummaryProvider;
use App\Stash\Ai\ProviderError;
use App\Stash\Ai\SummaryProvider;
use App\Stash\Ai\SummaryResponse;
use App\Stash\Ai\SummaryService;
use App\Stash\Enums\AiRunStatus;
use App\Stash\Jobs\GenerateSummary;
use App\Stash\Models\AiRun;
use App\Stash\Models\Resource;
use App\Stash\Support\RequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\StashTesting;
use Tests\TestCase;

/** Spec §14.10 — disabled UX, mocked success, cache, budget, invalid output, timeout, data safety. */
class AiSummaryTest extends TestCase
{
    use RefreshDatabase, StashTesting;

    private const TEXT = 'Calm interfaces reduce cognitive load. They prefer clear hierarchy, generous spacing and predictable motion. Teams that adopt calm design report fewer support tickets and happier users over time.';

    private User $user;

    private Resource $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStash();
        $this->user = $this->owner();
        $this->article = $this->resource($this->user, [
            'type' => 'article', 'reading_status' => 'unread', 'title' => 'Calm interfaces',
            'description' => 'Original description', 'personal_notes' => 'PRIVATE NOTE never send',
        ]);
    }

    private function enable(SummaryProvider $provider, int $limit = 100_000): void
    {
        config(['stash.ai.enabled' => true, 'stash.ai.monthly_token_limit' => $limit]);
        $this->app->instance(SummaryProvider::class, $provider);
    }

    private function start(array $body = []): TestResponse
    {
        return $this->actingAs($this->user)->postJson("/resources/{$this->article->id}/summaries", $body + ['source' => 'pasted', 'text' => self::TEXT]);
    }

    public function test_ai_is_disabled_gracefully_without_credentials(): void
    {
        config(['stash.ai.enabled' => true, 'services.deepseek.api_key' => '', 'services.deepseek.model' => '']);

        $this->start()->assertStatus(503)->assertJsonPath('error.code', 'ai_disabled');
        $this->actingAs($this->user)->get('/settings/ai')->assertInertia(fn ($p) => $p->where('status.enabled', false)
            ->where('status.reason', 'AI summaries need DEEPSEEK_API_KEY and DEEPSEEK_MODEL on the server.'));
        $this->actingAs($this->user)->get('/library')->assertInertia(fn ($p) => $p->where('ai.enabled', false));

        // Core features are unaffected.
        $this->actingAs($this->user)->patch("/resources/{$this->article->id}", ['title' => 'Still editable'])->assertSessionHasNoErrors();
        $this->assertSame(0, AiRun::query()->count());
    }

    public function test_successful_summary_with_preview_usage_and_no_private_data(): void
    {
        $fake = new FakeSummaryProvider;
        $this->enable($fake);

        $preview = $this->actingAs($this->user)->postJson("/resources/{$this->article->id}/summaries/preview", ['source' => 'pasted', 'text' => self::TEXT])
            ->assertOk()->json('data');
        $this->assertSame(self::TEXT, $preview['text']);
        $this->assertGreaterThan(0, $preview['estimated_tokens']);
        $this->assertSame(0, $fake->calls, 'preview spends nothing');

        $runId = $this->start()->assertStatus(202)->json('data.id');   // sync queue in tests runs the job
        $run = AiRun::query()->findOrFail($runId);

        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $this->assertStringStartsWith('Summary of', $run->result['summary']);
        $this->assertSame(160, $run->total_tokens);
        $this->assertFalse($run->usage_estimated);
        $this->assertNull($run->input_text, 'input text is not retained after success');
        $this->assertStringNotContainsString('PRIVATE NOTE', (string) $fake->lastText);

        $fresh = $this->article->fresh();
        $this->assertSame('Original description', $fresh->description, 'summary stored separately');
        $this->assertSame('PRIVATE NOTE never send', $fresh->personal_notes);

        $this->actingAs($this->user)->get("/library?resource={$this->article->id}")
            ->assertInertia(fn ($p) => $p->where('selected.summary.result.summary', $run->result['summary']));
    }

    public function test_identical_input_is_served_from_cache(): void
    {
        $fake = new FakeSummaryProvider;
        $this->enable($fake);

        $this->start()->assertStatus(202);
        $second = $this->start()->assertStatus(202)->json('data');

        $this->assertTrue($second['cached']);
        $this->assertSame(1, $fake->calls);
        $this->assertSame(0, $second['usage']['total_tokens']);
    }

    public function test_monthly_budget_is_reserved_and_enforced(): void
    {
        Queue::fake();
        $this->enable(new FakeSummaryProvider, limit: 1500);

        $first = $this->start()->assertStatus(202)->json('data');
        $this->assertSame('queued', $first['status']);
        $reserved = $first['usage']['reserved_tokens'];
        $this->assertGreaterThan(0, $reserved);

        // A concurrent request cannot exceed the limit while the first is still reserved.
        $other = str_replace('Calm', 'Quiet', self::TEXT);
        $this->start(['text' => $other])->assertStatus(429)->assertJsonPath('error.code', 'ai_budget_exceeded');

        $this->actingAs($this->user)->get('/settings/ai')->assertInertia(fn ($p) => $p->where('budget.used', $reserved));
    }

    public function test_metadata_only_summaries_require_explicit_acknowledgement(): void
    {
        $fake = new FakeSummaryProvider;
        $this->enable($fake);

        $preview = $this->actingAs($this->user)->postJson("/resources/{$this->article->id}/summaries/preview", ['source' => 'metadata'])->json('data');
        $this->assertNotNull($preview['limitation']);

        $this->start(['source' => 'metadata', 'text' => null])->assertStatus(422)->assertJsonPath('error.code', 'metadata_ack_required');
        $this->start(['source' => 'metadata', 'text' => null, 'confirm_metadata_only' => true])->assertStatus(202);
        $this->assertStringContainsString('Title: Calm interfaces', (string) $fake->lastText);

        // Only articles can be summarized.
        $link = $this->resource($this->user);
        $this->actingAs($this->user)->postJson("/resources/{$link->id}/summaries", ['source' => 'pasted', 'text' => self::TEXT])->assertStatus(422);
    }

    public function test_deepseek_invalid_output_is_rejected_and_usage_recorded(): void
    {
        config(['services.deepseek.api_key' => 'test-key-not-real', 'services.deepseek.model' => 'deepseek-flash', 'services.deepseek.base_url' => 'https://api.deepseek.test']);
        $this->enable(new DeepSeekProvider);
        Http::fake(['api.deepseek.test/*' => Http::response([
            'model' => 'deepseek-flash',
            'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Sure! Here is a summary without JSON']]],
            'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 20, 'total_tokens' => 320],
        ])]);

        $run = AiRun::query()->findOrFail($this->start()->json('data.id'));
        $this->assertSame(AiRunStatus::Failed, $run->status);
        $this->assertSame('malformed', $run->error_code);
        $this->assertSame(320, $run->total_tokens, 'actual usage counted even when output is invalid');

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://api.deepseek.test/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key-not-real')
                && $body['response_format'] === ['type' => 'json_object']
                && $body['thinking'] === ['type' => 'disabled']
                && str_contains($body['messages'][1]['content'], '<untrusted_text>')
                && ! str_contains(json_encode($body), 'PRIVATE NOTE');
        });
        $this->assertSame('Original description', $this->article->fresh()->description);
    }

    public function test_deepseek_success_truncation_refusal_and_blank_responses(): void
    {
        config(['services.deepseek.api_key' => 'k', 'services.deepseek.model' => 'deepseek-flash', 'services.deepseek.base_url' => 'https://api.deepseek.test']);
        $provider = new DeepSeekProvider;
        $ok = ['model' => 'deepseek-flash', 'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"summary":"Short.","key_points":["a","b"]}']]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3]];

        Http::fakeSequence('api.deepseek.test/*')
            ->push($ok)
            ->push(['choices' => [['finish_reason' => 'length', 'message' => ['content' => '{"summary":"Cut']]]])
            ->push(['choices' => [['finish_reason' => 'content_filter', 'message' => ['content' => '']]]])
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '     ']]]])
            ->push(['error' => ['message' => 'Rate limit']], 429)
            ->push(['error' => ['message' => 'bad key']], 401);

        $res = $provider->summarize('text', 'article', 100);
        $this->assertSame('Short.', $res->summary);
        $this->assertSame(['a', 'b'], $res->keyPoints);

        foreach (['truncated', 'refused', 'blank', 'rate_limited', 'auth'] as $expected) {
            try {
                $provider->summarize('text', 'article', 100);
                $this->fail("expected {$expected}");
            } catch (ProviderError $e) {
                $this->assertSame($expected, $e->category);
            }
        }
    }

    public function test_timeouts_are_never_retried_and_count_the_reservation(): void
    {
        config(['services.deepseek.api_key' => 'k', 'services.deepseek.model' => 'deepseek-flash', 'services.deepseek.base_url' => 'https://api.deepseek.test']);
        $this->enable(new DeepSeekProvider);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 28: Operation timed out after 60000 milliseconds');
        });

        $run = AiRun::query()->findOrFail($this->start()->json('data.id'));
        $this->assertSame(AiRunStatus::Failed, $run->status);
        $this->assertSame('timeout', $run->error_code);
        $this->assertSame(1, $calls, 'ambiguous timeouts are not retried automatically');
        $this->assertTrue($run->usage_estimated);
        $this->assertSame($run->reserved_tokens, $run->total_tokens);
        $this->assertSame('Calm interfaces', $this->article->fresh()->title);
    }

    public function test_rate_limits_are_retried_with_backoff_then_succeed(): void
    {
        $fake = (new FakeSummaryProvider)->failWith('rate_limited')->respondWith(new SummaryResponse('Recovered.', [], null, 'fake-model'));
        $this->enable($fake);
        Queue::fake();

        $runId = $this->start()->json('data.id');
        $run = AiRun::query()->findOrFail($runId);

        $service = app(SummaryService::class);
        $retryIn = $service->execute($run);
        $this->assertNotNull($retryIn, 'retryable error schedules a release');
        $this->assertSame(AiRunStatus::Queued, $run->fresh()->status);

        $this->assertNull($service->execute($run->fresh()));
        $run->refresh();
        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $this->assertSame('Recovered.', $run->result['summary']);
        $this->assertTrue($run->usage_estimated, 'unknown usage keeps the estimate');
        $this->assertSame($run->reserved_tokens, $run->total_tokens);
    }

    public function test_interrupted_runs_are_not_resent(): void
    {
        $fake = new FakeSummaryProvider;
        $this->enable($fake);
        Queue::fake();
        $run = AiRun::query()->findOrFail($this->start()->json('data.id'));
        $run->forceFill(['status' => AiRunStatus::Running])->save();

        (new GenerateSummary($run->id))->handle(app(SummaryService::class), app(RequestContext::class));

        $this->assertSame(0, $fake->calls);
        $this->assertSame('timeout', $run->fresh()->error_code);
    }

    public function test_api_summary_endpoint_requires_ai_ability_and_supports_preview(): void
    {
        $this->enable(new FakeSummaryProvider);
        $readOnly = $this->apiToken($this->user, ['resources:read']);
        $this->withHeaders($this->bearer($readOnly))->postJson("/api/v1/resources/{$this->article->id}/summaries", ['source' => 'pasted', 'text' => self::TEXT])
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $token = $this->apiToken($this->user, ['ai:run', 'resources:read']);
        $this->withHeaders($this->bearer($token))->postJson("/api/v1/resources/{$this->article->id}/summaries", ['source' => 'pasted', 'text' => self::TEXT, 'preview_only' => true])
            ->assertOk()->assertJsonPath('data.text', self::TEXT);
        $runId = $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'sum-1'])
            ->postJson("/api/v1/resources/{$this->article->id}/summaries", ['source' => 'pasted', 'text' => self::TEXT])->assertStatus(202)->json('data.id');
        $this->withHeaders($this->bearer($token))->getJson("/api/v1/ai-runs/{$runId}")->assertOk()->assertJsonPath('data.status', 'succeeded');
    }
}

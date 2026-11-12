<?php

namespace Tests\Feature\Stash;

use App\Stash\Enums\MetadataStatus;
use App\Stash\Fetch\FetchFailedException;
use App\Stash\Jobs\EnrichResourceMetadata;
use App\Stash\Metadata\MetadataEnricher;
use App\Stash\Models\Resource;
use App\Stash\Resources\ResourceService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Message\RequestInterface;
use Tests\Support\StashTesting;
use Tests\TestCase;

/** Spec §14.2 — quick save, queued enrichment, failure tolerance, manual-edit protection. */
class MetadataPipelineTest extends TestCase
{
    use RefreshDatabase, StashTesting;

    private const PAGE = <<<'HTML'
<!doctype html><html><head>
<title>Fallback Title</title>
<meta property="og:title" content="Checkout Flow Patterns">
<meta name="description" content="Plain description">
<meta property="og:description" content="Edge cases for checkout &amp; payment.">
<meta property="og:site_name" content="Flow Design">
<link rel="icon" href="/static/favicon.png">
<base href="https://flow.design/articles/">
</head><body><p>Hi</p></body></html>
HTML;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStash();
    }

    public function test_url_save_returns_immediately_with_queued_metadata(): void
    {
        Queue::fake();
        $user = $this->owner();

        $this->actingAs($user)->post('/resources', ['url' => 'https://www.flow.design/checkout?utm_source=x', 'type' => 'link'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $r = Resource::query()->firstOrFail();
        $this->assertSame('flow.design', $r->title, 'provisional host-based title');
        $this->assertSame(MetadataStatus::Queued, $r->metadata_status);
        $this->assertSame([], $r->manual_fields);
        Queue::assertPushed(EnrichResourceMetadata::class, fn ($job) => $job->resourceId === $r->id);
    }

    public function test_enrichment_applies_fetched_metadata(): void
    {
        $this->fakeNetwork(['flow.design' => ['93.184.216.34']], [$this->html(self::PAGE)]);
        $user = $this->owner();
        $r = $this->resource($user, ['title' => 'flow.design', 'description' => null, 'original_url' => 'https://flow.design/checkout', 'metadata_status' => MetadataStatus::Queued]);

        $this->assertSame('ready', app(MetadataEnricher::class)->enrich($r->id));

        $r->refresh();
        $this->assertSame('Checkout Flow Patterns', $r->title);
        $this->assertSame('Edge cases for checkout & payment.', $r->description);
        $this->assertSame('Flow Design', $r->metadata['site_name']);
        $this->assertSame('https://flow.design/static/favicon.png', $r->metadata['fetched']['favicon_url'], 'relative URL resolved against <base>');
        $this->assertSame(MetadataStatus::Ready, $r->metadata_status);
        $this->assertSame(2, $r->version);
    }

    public function test_timeout_preserves_a_usable_resource(): void
    {
        $this->fakeNetwork([], [fn (RequestInterface $req) => throw new ConnectException('cURL error 28: Operation timed out', $req)]);
        $user = $this->owner();
        $r = $this->resource($user, ['title' => 'My title', 'metadata_status' => MetadataStatus::Queued]);

        $this->assertSame('failed', app(MetadataEnricher::class)->enrich($r->id));

        $r->refresh();
        $this->assertSame('My title', $r->title);
        $this->assertSame(MetadataStatus::Failed, $r->metadata_status);
        $this->assertStringContainsString('too long', (string) $r->metadata_error);
        $this->assertNull($r->deleted_at);
    }

    public function test_transient_failure_is_retried_by_the_job_then_marked_failed(): void
    {
        $this->fakeNetwork([], [fn (RequestInterface $req) => throw new ConnectException('cURL error 7: Failed to connect', $req)]);
        $user = $this->owner();
        $r = $this->resource($user, ['metadata_status' => MetadataStatus::Queued]);

        $this->expectException(FetchFailedException::class);
        try {
            app(MetadataEnricher::class)->enrich($r->id, rethrowTransient: true);
        } finally {
            $this->assertSame(MetadataStatus::Queued, $r->fresh()->metadata_status, 'transient failure stays queued for the retry');
        }
    }

    public function test_sync_queue_metadata_failures_never_break_the_save_request(): void
    {
        // QUEUE_CONNECTION=sync runs the after-commit job inside the request.
        $this->fakeNetwork([], [fn (RequestInterface $req) => throw new ConnectException('cURL error 7: Failed to connect', $req)]);
        $user = $this->owner();

        $this->actingAs($user)->post('/resources', ['url' => 'https://unreachable.example/page', 'title' => 'Kept'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $r = Resource::query()->firstOrFail();
        $this->assertSame('Kept', $r->title);
        $this->assertSame(MetadataStatus::Failed, $r->metadata_status);
    }

    public function test_invalid_or_non_html_responses_keep_the_item(): void
    {
        $this->fakeNetwork([], [
            new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-1.4'),
            $this->html("\x00\x01 not really <html"),
        ]);
        $user = $this->owner();
        $a = $this->resource($user, ['title' => 'PDF link']);
        $b = $this->resource($user, ['title' => 'Garbage page']);

        $this->assertSame('failed', app(MetadataEnricher::class)->enrich($a->id));
        $this->assertSame('PDF link', $a->fresh()->title);
        $this->assertStringContainsString('not an HTML page', (string) $a->fresh()->metadata_error);

        $this->assertSame('ready', app(MetadataEnricher::class)->enrich($b->id));
        $this->assertSame('Garbage page', $b->fresh()->title);
    }

    public function test_enrichment_never_clobbers_manual_edits_made_while_it_runs(): void
    {
        $user = $this->owner();
        $r = $this->resource($user, ['title' => 'flow.design', 'description' => null, 'original_url' => 'https://flow.design/checkout', 'metadata_status' => MetadataStatus::Queued]);

        // The user edits the title while the HTTP request is in flight.
        $this->fakeNetwork([], [function () use ($user, $r) {
            app(ResourceService::class)->update($user, $r, ['title' => 'My own title'], 1);

            return $this->html(self::PAGE);
        }]);

        app(MetadataEnricher::class)->enrich($r->id);

        $r->refresh();
        $this->assertSame('My own title', $r->title, 'manual title kept');
        $this->assertSame('Edge cases for checkout & payment.', $r->description, 'non-manual field still enriched');
        $this->assertContains('title', $r->manual_fields);

        // The UI shows the proposed source value; replacing is explicit.
        $detail = $this->actingAs($user)->get("/library?resource={$r->id}")->viewData('page')['props']['selected'];
        $this->assertSame('Checkout Flow Patterns', $detail['source_proposals']['title']);

        $this->actingAs($user)->post("/resources/{$r->id}/apply-source", ['fields' => ['title'], 'version' => $r->version])->assertRedirect();
        $this->assertSame('Checkout Flow Patterns', $r->fresh()->title);
        $this->assertNotContains('title', $r->fresh()->manual_fields);
    }

    public function test_user_supplied_title_on_save_is_protected(): void
    {
        Queue::fake();
        $user = $this->owner();
        $this->actingAs($user)->post('/resources', ['url' => 'https://flow.design/x', 'title' => 'Typed by me', 'description' => 'Mine too']);
        $r = Resource::query()->firstOrFail();
        $this->assertEqualsCanonicalizing(['title', 'description'], $r->manual_fields);

        $this->fakeNetwork([], [$this->html(self::PAGE)]);
        app(MetadataEnricher::class)->enrich($r->id);
        $this->assertSame('Typed by me', $r->fresh()->title);
        $this->assertSame('Mine too', $r->fresh()->description);
    }
}

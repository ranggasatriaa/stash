<?php

namespace Tests\Feature\Stash;

use App\Stash\Models\Resource;
use App\Stash\Models\Tag;
use App\Stash\Support\UrlNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\Support\StashTesting;
use Tests\TestCase;

/** Spec §14.8 — bulk authorization, duplicate warnings, idempotency, stale updates. */
class BulkIdempotencyTest extends TestCase
{
    use RefreshDatabase, StashTesting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStash();
        Queue::fake();
    }

    public function test_bulk_actions_validate_every_id_and_report_skips(): void
    {
        $user = $this->owner();
        $other = $this->owner();
        $link = $this->resource($user, ['title' => 'Link']);
        $article = $this->resource($user, ['type' => 'article', 'reading_status' => 'unread']);
        $foreign = $this->resource($other, ['type' => 'article', 'reading_status' => 'unread']);

        $token = $this->apiToken($user, ['resources:write']);
        $response = $this->withHeaders($this->bearer($token))->postJson('/api/v1/resources/bulk', [
            'operation' => 'mark_read', 'ids' => [$link->id, $article->id, $foreign->id],
        ])->assertOk();

        $response->assertJsonPath('data.changed', [$article->id]);
        $skipped = collect($response->json('data.skipped'))->pluck('reason', 'id');
        $this->assertSame('not_article', $skipped[$link->id]);
        $this->assertSame('not_found', $skipped[$foreign->id], 'foreign ids are indistinguishable from missing ones');
        $this->assertSame('unread', $foreign->fresh()->reading_status->value);
        $this->assertSame(2, $article->fresh()->version);
        $this->assertSame(['operation' => 'mark_unread', 'ids' => [$article->id]], $response->json('data.undo'));
    }

    public function test_bulk_tags_project_archive_trash_and_undo(): void
    {
        $user = $this->owner();
        $a = $this->resource($user);
        $b = $this->resource($user);

        $this->actingAs($user)->post('/resources/bulk', ['operation' => 'add_tags', 'ids' => [$a->id, $b->id], 'tags' => ['Batch']])->assertSessionHasNoErrors();
        $this->assertSame('Batch', $a->fresh()->tag_names);
        $tagId = Tag::query()->value('id');

        $this->actingAs($user)->post('/resources/bulk', ['operation' => 'archive', 'ids' => [$a->id, $b->id]]);
        $this->assertSame(0, Resource::query()->active()->count());

        // Undo = inverse operation on the changed ids.
        $this->actingAs($user)->post('/resources/bulk', ['operation' => 'unarchive', 'ids' => [$a->id, $b->id]]);
        $this->assertSame(2, Resource::query()->active()->count());

        $this->actingAs($user)->post('/resources/bulk', ['operation' => 'remove_tags', 'ids' => [$a->id], 'tag_ids' => [$tagId]]);
        $this->assertSame('', $a->fresh()->tag_names);
        $this->assertSame('Batch', $b->fresh()->tag_names);

        $this->actingAs($user)->post('/resources/bulk', ['operation' => 'trash', 'ids' => [$a->id]]);
        $this->actingAs($user)->post('/resources/bulk', ['operation' => 'delete_permanently', 'ids' => [$a->id, $b->id]])->assertSessionHasErrors('confirm');
        $this->actingAs($user)->post('/resources/bulk', ['operation' => 'delete_permanently', 'ids' => [$a->id, $b->id], 'confirm' => true]);
        $this->assertNull(Resource::withTrashed()->find($a->id));
        $this->assertNotNull(Resource::query()->find($b->id), 'not in trash, skipped');
    }

    public function test_api_bulk_permanent_delete_requires_the_privileged_ability(): void
    {
        $user = $this->owner();
        $r = $this->resource($user);
        $r->delete();

        $this->withHeaders($this->bearer($this->apiToken($user, ['resources:write'])))
            ->postJson('/api/v1/resources/bulk', ['operation' => 'delete_permanently', 'ids' => [$r->id], 'confirm' => true])
            ->assertForbidden()->assertJsonPath('error.code', 'missing_ability');
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->bearer($this->apiToken($user, ['resources:write', 'resources:delete'])))
            ->postJson('/api/v1/resources/bulk', ['operation' => 'delete_permanently', 'ids' => [$r->id], 'confirm' => true])
            ->assertOk()->assertJsonPath('data.changed', [$r->id]);
    }

    public function test_duplicate_url_warnings_are_conservative_and_owner_scoped(): void
    {
        $user = $this->owner();
        $saved = $this->resource($user);
        $saved->update(['original_url' => 'https://Example.com:443/Article?id=7&utm_source=x#top']);
        $n = UrlNormalizer::normalize('https://Example.com:443/Article?id=7&utm_source=x#top');
        $saved->forceFill(['normalized_url' => $n['normalized'], 'normalized_url_hash' => $n['hash']])->save();

        $check = fn (string $url, $as = null) => $this->actingAs($as ?? $user)->postJson('/resources/duplicates', ['url' => $url])->json('duplicates');

        $this->assertCount(1, $check('http://example.com/Article?id=7&fbclid=abc'), 'scheme, port, fragment and tracking params ignored');
        $this->assertCount(0, $check('https://example.com/Article?id=8'), 'meaningful query parameters preserved');
        $this->assertCount(0, $check('https://example.com/article?id=7'), 'path case preserved');
        $this->assertCount(0, $check('https://example.com/Article?id=7', $this->owner()), 'other owners are never consulted');

        // Saving anyway is allowed; the API reports the duplicate in meta.
        $token = $this->apiToken($user, ['resources:write']);
        $this->withHeaders($this->bearer($token))->postJson('/api/v1/resources', ['url' => 'https://example.com/Article?id=7'])
            ->assertCreated()->assertJsonPath('meta.duplicates.0.id', $saved->id);
    }

    public function test_idempotent_creates_replay_and_reject_mismatched_bodies(): void
    {
        $user = $this->owner();
        $headers = $this->bearer($this->apiToken($user, ['resources:write'])) + ['Idempotency-Key' => 'create-123'];

        $first = $this->withHeaders($headers)->postJson('/api/v1/resources', ['url' => 'https://example.com/a', 'tags' => ['x']])->assertCreated();
        $second = $this->withHeaders($headers)->postJson('/api/v1/resources', ['tags' => ['x'], 'url' => 'https://example.com/a'])->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, Resource::query()->count());

        $this->withHeaders($headers)->postJson('/api/v1/resources', ['url' => 'https://example.com/DIFFERENT'])
            ->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
        $this->assertSame(1, Resource::query()->count());

        // Keys are scoped per credential.
        $this->app['auth']->forgetGuards();
        $otherToken = $this->bearer($this->apiToken($user, ['resources:write'])) + ['Idempotency-Key' => 'create-123'];
        $this->withHeaders($otherToken)->postJson('/api/v1/resources', ['url' => 'https://example.com/DIFFERENT'])->assertCreated();
    }

    public function test_idempotent_multipart_uploads_hash_the_file(): void
    {
        $user = $this->owner();
        $headers = $this->bearer($this->apiToken($user, ['resources:write'])) + ['Idempotency-Key' => 'upload-1'];
        $bytes = $this->pngBytes();

        $this->withHeaders($headers)->post('/api/v1/resources', ['file' => UploadedFile::fake()->createWithContent('a.png', $bytes)])->assertCreated();
        $this->withHeaders($headers)->post('/api/v1/resources', ['file' => UploadedFile::fake()->createWithContent('a.png', $bytes)])->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true');
        $this->withHeaders($headers)->post('/api/v1/resources', ['file' => UploadedFile::fake()->createWithContent('a.png', $this->pngBytes(60, 60))])->assertStatus(409);
        $this->assertSame(1, Resource::query()->count());
    }

    public function test_stale_updates_conflict_and_never_overwrite(): void
    {
        $user = $this->owner();
        $r = $this->resource($user, ['title' => 'Original']);
        $token = $this->bearer($this->apiToken($user, ['resources:write', 'resources:read']));

        $this->withHeaders($token)->patchJson("/api/v1/resources/{$r->id}", ['title' => 'Missing precondition'])
            ->assertStatus(428)->assertJsonPath('error.code', 'precondition_required');

        $this->withHeaders($token)->patchJson("/api/v1/resources/{$r->id}", ['title' => 'First writer', 'version' => 1])
            ->assertOk()->assertJsonPath('data.version', 2)->assertHeader('ETag', 'W/"2"');

        $conflict = $this->withHeaders($token)->patchJson("/api/v1/resources/{$r->id}", ['title' => 'Second writer', 'version' => 1])
            ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict');
        $this->assertSame(2, $conflict->json('error.details.current_version'));
        $this->assertSame('First writer', $conflict->json('error.details.current.title'));
        $this->assertSame('First writer', $r->fresh()->title);

        // If-Match is accepted as the precondition too.
        $this->withHeaders($token + ['If-Match' => 'W/"2"'])->patchJson("/api/v1/resources/{$r->id}", ['title' => 'Third'])->assertOk();

        // Web UI: conflict returns errors.version without overwriting.
        $this->flushHeaders();
        $this->actingAs($user)->patch("/resources/{$r->id}", ['title' => 'Stale web edit', 'version' => 1])->assertSessionHasErrors('version');
        $this->assertSame('Third', $r->fresh()->title);
    }
}

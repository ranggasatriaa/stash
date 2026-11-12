<?php

namespace Tests\Feature\Stash;

use App\Stash\Files\FileStore;
use App\Stash\Jobs\DeleteStoredFiles;
use App\Stash\Models\Resource;
use App\Stash\Models\ResourceFile;
use App\Stash\Resources\ResourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StashTesting;
use Tests\TestCase;

/** Spec §14.7 — reading state, archive/trash/restore, permanent delete and file cleanup. */
class LifecycleTest extends TestCase
{
    use RefreshDatabase, StashTesting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStash();
    }

    public function test_mark_read_and_unread_and_read_later_rules(): void
    {
        Queue::fake();
        $user = $this->owner();
        $article = $this->resource($user, ['type' => 'article', 'reading_status' => 'unread', 'read_later' => true]);

        // Opening details never marks an article read.
        $this->actingAs($user)->get("/library?resource={$article->id}")->assertOk();
        $this->assertSame('unread', $article->fresh()->reading_status->value);

        $this->actingAs($user)->patch("/resources/{$article->id}", ['reading_status' => 'read'])->assertSessionHasNoErrors();
        $this->assertNotNull($article->fresh()->read_at);

        // Removing from Read later keeps the reading status.
        $this->actingAs($user)->patch("/resources/{$article->id}", ['read_later' => false]);
        $this->assertFalse($article->fresh()->read_later);
        $this->assertSame('read', $article->fresh()->reading_status->value);

        $this->actingAs($user)->patch("/resources/{$article->id}", ['reading_status' => 'unread']);
        $this->assertNull($article->fresh()->read_at);

        // Non-articles cannot be read-later or have a reading status.
        $link = $this->resource($user);
        $this->actingAs($user)->patch("/resources/{$link->id}", ['read_later' => true])->assertSessionHasErrors('read_later');
        $this->actingAs($user)->patch("/resources/{$link->id}", ['reading_status' => 'read'])->assertSessionHasErrors('reading_status');
    }

    public function test_type_conversion_rules(): void
    {
        Queue::fake();
        $user = $this->owner();
        $link = $this->resource($user);

        $this->actingAs($user)->patch("/resources/{$link->id}", ['type' => 'article'])->assertSessionHasNoErrors();
        $this->assertSame('unread', $link->fresh()->reading_status->value, 'converting to article initializes unread');

        $this->actingAs($user)->patch("/resources/{$link->id}", ['reading_status' => 'read', 'read_later' => true]);
        $this->actingAs($user)->patch("/resources/{$link->id}", ['type' => 'link']);
        $fresh = $link->fresh();
        $this->assertNull($fresh->reading_status);
        $this->assertFalse($fresh->read_later);
        $this->assertNull($fresh->read_at);

        // A link without an uploaded original cannot become an image.
        $this->actingAs($user)->patch("/resources/{$link->id}", ['type' => 'image'])->assertSessionHasErrors('type');
    }

    public function test_archive_trash_restore_invariants(): void
    {
        $user = $this->owner();
        $service = app(ResourceService::class);
        $r = $this->resource($user);

        $service->archive($user, $r);
        $service->trash($user, $r);
        $this->assertNotNull(Resource::withTrashed()->find($r->id)->archived_at);

        // Archiving a trashed item is refused.
        $this->actingAs($user)->post("/resources/{$r->id}/archive")->assertNotFound();

        $this->actingAs($user)->post("/resources/{$r->id}/restore")->assertRedirect();
        $restored = $r->fresh();
        $this->assertNull($restored->deleted_at);
        $this->assertNotNull($restored->archived_at, 'restore preserves the previous archive state');

        $this->actingAs($user)->post("/resources/{$r->id}/unarchive")->assertRedirect();
        $this->assertNull($r->fresh()->archived_at);
        $this->assertSame(5, $r->fresh()->version);
    }

    public function test_permanent_delete_requires_trash_and_confirmation_and_removes_files(): void
    {
        $user = $this->owner();
        $this->actingAs($user)->post('/resources', ['file' => $this->uploadedPng()]);
        $r = Resource::query()->firstOrFail();
        $keys = ResourceFile::query()->pluck('storage_key')->all();
        $this->assertCount(3, $keys);

        $this->actingAs($user)->delete("/resources/{$r->id}/permanent", ['confirm' => true])->assertSessionHasErrors();
        $this->assertNotNull(Resource::query()->find($r->id), 'not in trash yet');

        $this->actingAs($user)->delete("/resources/{$r->id}");
        $this->actingAs($user)->delete("/resources/{$r->id}/permanent", [])->assertSessionHasErrors('confirm');

        $this->actingAs($user)->delete("/resources/{$r->id}/permanent", ['confirm' => true])->assertRedirect();
        $this->assertNull(Resource::withTrashed()->find($r->id));
        $this->assertSame(0, ResourceFile::query()->count());
        foreach ($keys as $key) {
            Storage::disk('stash')->assertMissing($key);
        }
    }

    public function test_file_cleanup_retries_tolerate_already_missing_files(): void
    {
        Storage::disk('stash')->put('users/1/resources/x/original-a.png', 'a');
        $job = new DeleteStoredFiles([['stash', 'users/1/resources/x/original-a.png'], ['stash', 'users/1/resources/x/missing.png']]);

        $job->handle(app(FileStore::class));
        $job->handle(app(FileStore::class)); // retry: no error
        Storage::disk('stash')->assertMissing('users/1/resources/x/original-a.png');
    }

    public function test_storage_keys_cannot_escape_the_disk(): void
    {
        $this->expectException(\RuntimeException::class);
        app(FileStore::class)->delete('stash', '../../.env');
    }
}

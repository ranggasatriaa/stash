<?php

namespace Tests\Feature\Stash;

use App\Stash\Enums\ResourceType;
use App\Stash\Models\Resource;
use App\Stash\Models\ResourceFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StashTesting;
use Tests\TestCase;

/** Video upload: content sniffing, poster frame, limits, ranged private delivery. */
class VideoUploadTest extends TestCase
{
    use RefreshDatabase, StashTesting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStash();
    }

    private function mp4(string $name = 'Demo reel.mp4'): UploadedFile
    {
        $bytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 4096);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    public function test_video_is_stored_with_poster_thumbnail(): void
    {
        $user = $this->owner();

        $this->actingAs($user)->post('/resources', [
            'file' => $this->mp4(),
            'poster' => $this->uploadedPng('poster.png', 1280, 720),
            'tags' => ['Demos'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $r = Resource::query()->firstOrFail();
        $this->assertSame(ResourceType::Video, $r->type);
        $this->assertSame('Demo reel', $r->title);

        $files = ResourceFile::query()->where('resource_id', $r->id)->get()->keyBy(fn ($f) => $f->role->value);
        $this->assertSame(['original', 'preview', 'thumbnail'], $files->keys()->sort()->values()->all());
        $this->assertSame('video/mp4', $files['original']->mime_type);
        $this->assertSame('mp4', $files['original']->extension);
        $this->assertSame('image/webp', $files['thumbnail']->mime_type);
        Storage::disk('stash')->assertExists($files['original']->storage_key);
    }

    public function test_video_without_poster_still_saves(): void
    {
        $this->actingAs($this->owner())->post('/resources', ['file' => $this->mp4()])->assertSessionHasNoErrors();

        $this->assertSame(['original'], ResourceFile::query()->get()->map(fn ($f) => $f->role->value)->all());
    }

    public function test_bad_poster_does_not_fail_the_upload(): void
    {
        $this->actingAs($this->owner())->post('/resources', [
            'file' => $this->mp4(),
            'poster' => UploadedFile::fake()->createWithContent('poster.png', 'not an image'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Resource::query()->count());
        $this->assertSame(1, ResourceFile::query()->count());
    }

    public function test_spoofed_video_is_rejected(): void
    {
        $this->actingAs($this->owner())->post('/resources', [
            'file' => UploadedFile::fake()->createWithContent('clip.mp4', '<?php echo 1;'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, Resource::query()->count());
    }

    public function test_video_over_the_limit_is_rejected_and_nothing_is_stored(): void
    {
        config(['stash.videos.max_bytes' => 1024]);

        $this->actingAs($this->owner())->post('/resources', ['file' => $this->mp4()])->assertSessionHasErrors('file');

        $this->assertSame(0, Resource::query()->count());
        $this->assertSame([], Storage::disk('stash')->allFiles());
    }

    public function test_video_is_served_with_range_support_to_the_owner_only(): void
    {
        $user = $this->owner();
        $this->actingAs($user)->post('/resources', ['file' => $this->mp4()]);
        $file = ResourceFile::query()->where('role', 'original')->firstOrFail();

        $res = $this->actingAs($user)->get(route('files.show', $file), ['Range' => 'bytes=0-99']);
        $res->assertStatus(206);
        $this->assertSame('video/mp4', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));

        $this->actingAs($this->owner())->get(route('files.show', $file))->assertNotFound();
    }

    public function test_video_type_cannot_be_changed(): void
    {
        $user = $this->owner();
        $this->actingAs($user)->post('/resources', ['file' => $this->mp4()]);
        $r = Resource::query()->firstOrFail();

        $this->actingAs($user)->patchJson("/resources/{$r->id}", ['type' => 'link', 'version' => $r->version])
            ->assertStatus(422);
    }
}

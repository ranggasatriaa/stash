<?php

namespace Tests\Feature\Stash;

use App\Stash\Enums\MetadataStatus;
use App\Stash\Enums\ResourceType;
use App\Stash\Jobs\ProcessResourceImage;
use App\Stash\Models\Resource;
use App\Stash\Models\ResourceFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StashTesting;
use Tests\TestCase;

/** Spec §14.4 — valid processing, spoofing, limits, orientation, private delivery. */
class ImageUploadTest extends TestCase
{
    use RefreshDatabase, StashTesting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStash();
    }

    public function test_valid_image_is_stored_and_processed_into_bounded_variants(): void
    {
        $user = $this->owner();
        // QUEUE_CONNECTION=sync in tests: the after-commit job runs immediately.
        $this->actingAs($user)->post('/resources', [
            'file' => $this->uploadedPng('Dashboard idea.png', 2000, 1000),
            'tags' => ['UI/UX'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $r = Resource::query()->firstOrFail();
        $this->assertSame(ResourceType::Image, $r->type);
        $this->assertSame('Dashboard idea', $r->title);
        $this->assertSame(MetadataStatus::Ready, $r->fresh()->metadata_status);

        $files = ResourceFile::query()->where('resource_id', $r->id)->get()->keyBy(fn ($f) => $f->role->value);
        $this->assertSame(['original', 'preview', 'thumbnail'], $files->keys()->sort()->values()->all());
        $this->assertSame('image/png', $files['original']->mime_type);
        $this->assertSame(hash('sha256', Storage::disk('stash')->get($files['original']->storage_key)), $files['original']->checksum_sha256);
        $this->assertSame('image/webp', $files['thumbnail']->mime_type);
        $this->assertSame(640, $files['thumbnail']->width);
        $this->assertSame(1600, $files['preview']->width);
        $this->assertSame(800, $files['preview']->height);
        foreach ($files as $file) {
            Storage::disk('stash')->assertExists($file->storage_key);
        }
    }

    public function test_exif_orientation_is_applied_and_metadata_is_stripped(): void
    {
        $user = $this->owner();
        $upload = UploadedFile::fake()->createWithContent('rotated.jpg', $this->jpegWithExifOrientation(200, 100));

        $this->actingAs($user)->post('/resources', ['file' => $upload])->assertSessionHasNoErrors();

        $thumb = ResourceFile::query()->where('role', 'thumbnail')->firstOrFail();
        $this->assertSame(100, $thumb->width, 'rotated 90°: width/height swapped');
        $this->assertSame(200, $thumb->height);

        $bytes = Storage::disk('stash')->get($thumb->storage_key);
        $this->assertStringNotContainsString('Exif', $bytes);
        $this->assertStringNotContainsString('GPS', $bytes);
    }

    public function test_spoofed_svg_html_and_executables_are_rejected(): void
    {
        Queue::fake();
        $user = $this->owner();
        $cases = [
            'fake.png' => '<?php system($_GET["c"]); ?>',
            'drawing.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'page.webp' => '<!doctype html><html><script>alert(1)</script></html>',
            'tool.jpg' => "MZ\x90\x00".str_repeat("\x00", 200),
        ];

        foreach ($cases as $name => $content) {
            $upload = UploadedFile::fake()->createWithContent($name, $content);
            $this->actingAs($user)->post('/resources', ['file' => $upload])->assertSessionHasErrors('file');
            $this->assertErrorContains('Only JPEG, PNG and WebP images are supported');
        }

        // Real PNG bytes declared as JPEG: the declared type must match the content.
        $mismatch = UploadedFile::fake()->createWithContent('photo.jpg', $this->pngBytes());
        $this->actingAs($user)->post('/resources', ['file' => $mismatch])->assertSessionHasNoErrors();
        $this->assertSame('image/png', ResourceFile::query()->firstOrFail()->mime_type, 'server detection wins over the client name');

        $this->assertSame(1, Resource::query()->count());
        Queue::assertPushed(ProcessResourceImage::class, 1);
    }

    public function test_size_dimension_and_pixel_limits(): void
    {
        Queue::fake();
        $user = $this->owner();

        config(['stash.uploads.max_bytes' => 500]);
        $this->actingAs($user)->post('/resources', ['file' => $this->uploadedPng('big.png', 300, 300)])
            ->assertSessionHasErrors('file');

        config(['stash.uploads.max_bytes' => 10 * 1024 * 1024, 'stash.uploads.max_pixels' => 10_000]);
        $this->actingAs($user)->post('/resources', ['file' => $this->uploadedPng('many-pixels.png', 200, 100)])
            ->assertSessionHasErrors('file');
        $this->assertErrorContains('megapixels (200×100)');

        config(['stash.uploads.max_pixels' => 40_000_000, 'stash.uploads.max_dimension' => 150]);
        $this->actingAs($user)->post('/resources', ['file' => $this->uploadedPng('wide.png', 400, 10)])
            ->assertSessionHasErrors('file');
        $this->assertErrorContains('each side');

        $this->assertSame(0, Resource::query()->count());
        $this->assertSame([], Storage::disk('stash')->allFiles(), 'rejected uploads leave no stored files');
    }

    public function test_attribution_url_and_duplicate_checksum_warning(): void
    {
        Queue::fake();
        $user = $this->owner();
        $bytes = $this->pngBytes(50, 50);

        $this->actingAs($user)->post('/resources', ['file' => UploadedFile::fake()->createWithContent('a.png', $bytes), 'url' => 'https://dribbble.com/shots/1'])->assertSessionHasNoErrors();
        $first = Resource::query()->firstOrFail();
        $this->assertSame('https://dribbble.com/shots/1', $first->original_url);
        $this->assertSame('dribbble.com', $first->source_host);

        $this->actingAs($user)->postJson('/resources/duplicates', ['sha256' => hash('sha256', $bytes)])
            ->assertOk()->assertJsonPath('duplicates.0.id', $first->id);

        // Save anyway is allowed.
        $this->actingAs($user)->post('/resources', ['file' => UploadedFile::fake()->createWithContent('b.png', $bytes)])->assertSessionHasNoErrors();
        $this->assertSame(2, Resource::query()->count());

        // Another owner's identical image is not reported.
        $other = $this->owner();
        $this->actingAs($other)->postJson('/resources/duplicates', ['sha256' => hash('sha256', $bytes)])->assertJsonCount(0, 'duplicates');
    }

    public function test_failed_processing_keeps_the_original_and_can_be_retried(): void
    {
        $user = $this->owner();
        Queue::fake();
        $this->actingAs($user)->post('/resources', ['file' => $this->uploadedPng()]);
        $r = Resource::query()->firstOrFail();

        (new ProcessResourceImage($r->id))->failed(new \RuntimeException('decoder crashed'));
        // The worker releases the unique lock when a job fails; simulate that here.
        Cache::lock('laravel_unique_job:'.ProcessResourceImage::class.':'.$r->id)->forceRelease();
        $this->assertSame(MetadataStatus::Failed, $r->fresh()->metadata_status);
        $this->assertSame(1, ResourceFile::query()->where('role', 'original')->count());

        $this->actingAs($user)->post("/resources/{$r->id}/refresh")->assertRedirect();
        Queue::assertPushed(ProcessResourceImage::class, 2);
        $this->assertSame(MetadataStatus::Queued, $r->fresh()->metadata_status);
    }

    private function assertErrorContains(string $needle): void
    {
        $messages = implode(' | ', session('errors')?->get('file') ?? []);
        $this->assertStringContainsString($needle, $messages);
    }
}

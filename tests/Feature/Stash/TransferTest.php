<?php

namespace Tests\Feature\Stash;

use App\Models\User;
use App\Stash\Enums\AiRunStatus;
use App\Stash\Models\AiRun;
use App\Stash\Models\DataExport;
use App\Stash\Models\DataImport;
use App\Stash\Models\Project;
use App\Stash\Models\Resource;
use App\Stash\Models\ResourceFile;
use App\Stash\Models\Tag;
use App\Stash\Resources\ResourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\StashTesting;
use Tests\TestCase;
use ZipArchive;

/** Spec §14.9 — JSON/ZIP round trip, malicious archives, Obsidian YAML and links. */
class TransferTest extends TestCase
{
    use RefreshDatabase, StashTesting;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStash();
        $this->user = $this->owner();
    }

    private function library(): array
    {
        $project = Project::query()->forceCreate(['user_id' => $this->user->id, 'name' => 'Website Redesign', 'color' => '#FF7417']);
        $service = app(ResourceService::class);
        // Network is not used: metadata jobs fail fast against the fake network.
        $this->fakeNetwork([], array_fill(0, 10, $this->html('<title>x</title>')));

        $article = $service->createFromUrl($this->user, [
            'url' => 'https://example.com/post', 'type' => 'article', 'title' => 'Quotes: "Yes" & #hash [link]', 'tags' => ['UI/UX', 'Riset'],
            'project_id' => $project->id, 'read_later' => true, 'reading_status' => 'read', 'personal_notes' => "## Notes\n\n- one\n- two",
        ])['resource'];
        $service->archive($this->user, $article);
        $image = $service->createFromUpload($this->user, $this->uploadedPng('board.png', 300, 200), ['tags' => ['UI/UX']])['resource'];
        $trashed = $service->createFromUrl($this->user, ['url' => 'https://example.com/old', 'title' => 'Old'])['resource'];
        $service->trash($this->user, $trashed);

        $run = new AiRun(['resource_id' => $article->id, 'task' => 'summary', 'provider' => 'deepseek', 'model' => 'deepseek-flash', 'prompt_version' => 'sum-v1',
            'status' => AiRunStatus::Succeeded, 'source_kind' => 'article', 'input_hash' => str_repeat('a', 64), 'input_chars' => 10, 'total_tokens' => 100,
            'result' => ['summary' => 'A short summary.', 'key_points' => ['Point one']]]);
        $run->user_id = $this->user->id;
        $run->save();

        return compact('project', 'article', 'image', 'trashed');
    }

    private function export(string $format, bool $trash = false): DataExport
    {
        $this->actingAs($this->user)->post('/settings/exports', ['format' => $format, 'include_trash' => $trash])->assertSessionHasNoErrors();
        $export = DataExport::query()->latest()->firstOrFail();
        $this->assertSame('ready', $export->status, (string) $export->error);

        return $export;
    }

    private function exportBytes(DataExport $export): string
    {
        $response = $this->actingAs($this->user)->get("/exports/{$export->id}/download");
        $response->assertOk();

        return $response->streamedContent();
    }

    public function test_zip_export_import_round_trip_preserves_relationships_and_attachments(): void
    {
        $fx = $this->library();
        $export = $this->export('zip', trash: true);
        $zipBytes = $this->exportBytes($export);

        $tmp = tempnam(sys_get_temp_dir(), 'exp');
        file_put_contents($tmp, $zipBytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $data = json_decode((string) $zip->getFromName('data.json'), true);
        $this->assertSame('stash.export.manifest', $manifest['schema']);
        $this->assertSame(1, $data['schema_version']);
        $this->assertSame(3, $data['counts']['resources']);
        $attachment = collect($manifest['files'])->first(fn ($f) => str_starts_with($f['path'], 'attachments/'));
        $this->assertSame($attachment['sha256'], hash('sha256', (string) $zip->getFromName($attachment['path'])));
        $this->assertStringNotContainsString('password', (string) $zip->getFromName('data.json'));
        $zip->close();

        // Wipe the library, then import into the same owner.
        Resource::withTrashed()->get()->each->forceDelete();
        Project::query()->delete();
        Tag::query()->delete();
        AiRun::query()->delete();

        $this->actingAs($this->user)->post('/settings/imports', ['file' => UploadedFile::fake()->createWithContent('backup.zip', $zipBytes)])->assertSessionHasNoErrors();
        $import = DataImport::query()->latest()->firstOrFail();
        $this->assertSame('ready', $import->status, (string) $import->error);
        $this->assertSame(3, $import->preview['counts']['valid_resources']);
        $this->assertSame(0, $import->preview['missing_attachments']);

        $this->actingAs($this->user)->post("/imports/{$import->id}/apply", ['collision_strategy' => 'skip'])->assertSessionHasNoErrors();
        $import->refresh();
        $this->assertSame('completed', $import->status, (string) $import->error);
        $this->assertSame(3, $import->report['created']['resources']);

        $article = Resource::withTrashed()->with('tags', 'project')->findOrFail($fx['article']->id);
        $this->assertSame('Quotes: "Yes" & #hash [link]', $article->title);
        $this->assertSame(['Riset', 'UI/UX'], $article->tags->pluck('name')->all());
        $this->assertSame('Website Redesign', $article->project->name);
        $this->assertTrue($article->read_later);
        $this->assertSame('read', $article->reading_status->value);
        $this->assertNotNull($article->archived_at);
        $this->assertSame($fx['article']->created_at->toIso8601String(), $article->created_at->toIso8601String());
        $this->assertSame('A short summary.', AiRun::query()->where('resource_id', $article->id)->firstOrFail()->result['summary']);
        $this->assertNotNull(Resource::onlyTrashed()->find($fx['trashed']->id));

        $original = ResourceFile::query()->where('resource_id', $fx['image']->id)->where('role', 'original')->firstOrFail();
        $this->assertSame($attachment['sha256'], $original->checksum_sha256);
        Storage::disk('stash')->assertExists($original->storage_key);
        $this->assertSame(3, ResourceFile::query()->where('resource_id', $fx['image']->id)->count(), 'variants regenerated by the queued job');

        // Re-importing with "skip" never duplicates or overwrites.
        $this->actingAs($this->user)->post('/settings/imports', ['file' => UploadedFile::fake()->createWithContent('backup.zip', $zipBytes)]);
        $again = DataImport::query()->whereKeyNot($import->id)->firstOrFail();
        $this->assertSame(3, $again->preview['collisions']['resources_same_owner']);
        $this->actingAs($this->user)->post("/imports/{$again->id}/apply", ['collision_strategy' => 'skip']);
        $this->assertSame(3, $again->fresh()->report['skipped'] ? count($again->fresh()->report['skipped']) : 0);
        $this->assertSame(3, Resource::withTrashed()->count());
    }

    public function test_json_export_excludes_trash_by_default_and_imports_as_copies(): void
    {
        $this->library();
        $json = $this->exportBytes($this->export('json'));
        $doc = json_decode($json, true);
        $this->assertSame(2, $doc['counts']['resources'], 'trash excluded by default');
        $this->assertNull($doc['resources'][0]['files'][0]['path'] ?? null);

        $this->actingAs($this->user)->post('/settings/imports', ['file' => UploadedFile::fake()->createWithContent('meta.json', $json)]);
        $import = DataImport::query()->latest()->firstOrFail();
        $this->assertSame('ready', $import->status);
        $this->assertSame(2, $import->preview['collisions']['resources_same_owner'], 'same-id collisions are reported');
        $this->actingAs($this->user)->post("/imports/{$import->id}/apply", ['collision_strategy' => 'duplicate']);
        $report = $import->fresh()->report;
        $this->assertSame(1, $report['created']['resources'], 'the link is copied with a new id');
        $this->assertSame('missing_attachment', $report['skipped'][0]['reason'], 'images need their attachment');
    }

    /** @return array<string, array{callable(ZipArchive): void, string}> */
    public static function maliciousArchives(): array
    {
        return [
            'zip slip' => [fn (ZipArchive $z) => $z->addFromString('../../evil.php', '<?php'), 'unsafe path'],
            'absolute path' => [fn (ZipArchive $z) => $z->addFromString('/etc/cron.d/x', 'x'), 'unsafe path'],
            'windows path' => [fn (ZipArchive $z) => $z->addFromString('C:/Windows/x.txt', 'x'), 'unsafe path'],
            'symlink' => [function (ZipArchive $z) {
                $z->addFromString('attachments/01h00000000000000000000000.png', '/etc/passwd');
                $z->setExternalAttributesName('attachments/01h00000000000000000000000.png', ZipArchive::OPSYS_UNIX, (0120777 << 16));
            }, 'symbolic links'],
            'zip bomb' => [fn (ZipArchive $z) => $z->addFromString('data.json', str_repeat('A', 30 * 1024 * 1024)), 'compression bomb'],
            'missing manifest' => [fn (ZipArchive $z) => $z->addFromString('data.json', '{}'), 'not a Stash export'],
        ];
    }

    /** @dataProvider maliciousArchives */
    #[DataProvider('maliciousArchives')]
    public function test_malicious_archives_are_rejected(callable $build, string $message): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mal');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $build($zip);
        $zip->close();

        $this->actingAs($this->user)->post('/settings/imports', ['file' => UploadedFile::fake()->createWithContent('bad.zip', (string) file_get_contents($path))]);
        $import = DataImport::query()->latest()->firstOrFail();

        $this->assertSame('invalid', $import->status);
        $this->assertStringContainsString($message, (string) $import->error);
        $this->assertSame(0, Resource::withTrashed()->count());
        $this->assertFileDoesNotExist(base_path('evil.php'));
    }

    public function test_tampered_attachment_checksums_and_entry_count_limits(): void
    {
        $this->library();
        $bytes = $this->exportBytes($this->export('zip'));
        $path = tempnam(sys_get_temp_dir(), 'tam');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $zip->open($path);
        $data = (string) $zip->getFromName('data.json');
        $zip->addFromString('data.json', str_replace('Website Redesign', 'Tampered', $data));
        $zip->close();

        $this->actingAs($this->user)->post('/settings/imports', ['file' => UploadedFile::fake()->createWithContent('t.zip', (string) file_get_contents($path))]);
        $tampered = DataImport::query()->firstOrFail();
        $this->assertStringContainsString('checksum', (string) $tampered->error);

        config(['stash.imports.max_entries' => 2]);
        $this->actingAs($this->user)->post('/settings/imports', ['file' => UploadedFile::fake()->createWithContent('n.zip', $bytes)]);
        $this->assertStringContainsString('entries', (string) DataImport::query()->whereKeyNot($tampered->id)->firstOrFail()->error);
    }

    public function test_obsidian_export_has_valid_yaml_and_relative_attachment_links(): void
    {
        $fx = $this->library();
        $bytes = $this->exportBytes($this->export('obsidian'));
        $path = tempnam(sys_get_temp_dir(), 'obs');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $zip->open($path);

        $note = (string) $zip->getFromName("Resources/{$fx['article']->id}.md");
        $this->assertMatchesRegularExpression('/^---\n(.*?)\n---\n/s', $note);
        preg_match('/^---\n(.*?)\n---\n/s', $note, $m);
        $front = Yaml::parse($m[1]);
        $this->assertSame($fx['article']->id, $front['id']);
        $this->assertSame('Quotes: "Yes" & #hash [link]', $front['title']);
        $this->assertSame(['Riset', 'UI-UX'], array_values(array_intersect(['Riset', 'UI-UX'], $front['tags'])));
        $this->assertSame(['Riset', 'UI/UX'], $front['stash_tags']);
        $this->assertSame("[[Projects/{$fx['project']->id}|Website Redesign]]", $front['project']);
        $this->assertTrue($front['archived']);
        $this->assertSame('read', $front['reading_status']);
        $this->assertStringContainsString('# Quotes: "Yes" & \\#hash \\[link\\]', $note, 'Markdown-escaped heading');
        $this->assertStringContainsString("## Notes\n\n## Notes", $note);
        $this->assertStringContainsString('## AI summary', $note);
        $this->assertStringContainsString('AI-generated summary', $note);

        $imageNote = (string) $zip->getFromName("Resources/{$fx['image']->id}.md");
        preg_match('/!\[.*?\]\(\.\.\/(attachments\/[^)]+)\)/', $imageNote, $link);
        $this->assertNotEmpty($link, 'relative attachment link');
        $this->assertNotFalse($zip->getFromName($link[1]), 'linked attachment exists in the archive');

        $projectNote = (string) $zip->getFromName("Projects/{$fx['project']->id}.md");
        $this->assertStringContainsString("../Resources/{$fx['article']->id}.md", $projectNote);
        $this->assertFalse($zip->getFromName("Resources/{$fx['trashed']->id}.md"), 'trash excluded by default');
        $zip->close();
    }

    public function test_exports_are_private_and_expire(): void
    {
        $this->library();
        $export = $this->export('json');
        $other = $this->owner();
        $this->actingAs($other)->get("/exports/{$export->id}/download")->assertNotFound();

        $export->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->actingAs($this->user)->get("/exports/{$export->id}/download")->assertStatus(410);

        $this->artisan('stash:prune')->assertSuccessful();
        $this->assertSame('expired', $export->fresh()->status);
        $this->assertSame([], Storage::disk('stash')->files("users/{$this->user->id}/exports"));
    }
}

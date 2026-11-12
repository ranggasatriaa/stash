<?php

namespace Database\Seeders;

use App\Models\User;
use App\Stash\Enums\FileRole;
use App\Stash\Enums\MetadataStatus;
use App\Stash\Enums\ReadingStatus;
use App\Stash\Enums\ResourceType;
use App\Stash\Files\FileStore;
use App\Stash\Images\ImageProcessor;
use App\Stash\Models\Project;
use App\Stash\Models\Resource;
use App\Stash\Models\ResourceFile;
use App\Stash\Resources\ResourceTagger;
use App\Stash\Support\UrlNormalizer;
use App\Stash\Taxonomy\TagService;
use Illuminate\Support\Str;

/**
 * Realistic development fixtures: every type, overlapping tags, projects,
 * unread/read articles, read-later, archive and Trash. Never runs in
 * production unless explicitly forced by the demo command.
 */
class DemoLibrarySeeder
{
    public function __construct(
        private readonly TagService $tags,
        private readonly ResourceTagger $tagger,
        private readonly FileStore $files,
        private readonly ImageProcessor $images,
    ) {}

    public function run(User $user): int
    {
        $projects = [];
        foreach ([
            ['Website Redesign', 'Inspiration and references for the new marketing site.', '#FF7417'],
            ['Thesis Research', 'Sources for the final paper.', '#3A7BD5'],
            ['Home Office', 'Furniture, lighting and setup ideas.', '#2E8B57'],
        ] as [$name, $description, $color]) {
            $p = new Project(['name' => $name, 'description' => $description, 'color' => $color]);
            $p->user_id = $user->id;
            $p->save();
            $projects[$name] = $p->id;
        }

        $items = [
            ['link', 'Checkout flow reference', 'https://flow.design/checkout-flow', 'Step-by-step checkout patterns with edge cases.', ['UI/UX', 'Inspiration'], 'Website Redesign', null, 2],
            ['article', 'Designing calm interfaces', 'https://example.com/articles/calm-interfaces', 'Why quiet UI earns attention.', ['UI/UX', 'Productivity'], 'Website Redesign', ['unread', true], 3],
            ['article', 'Focus habits for deep work', 'https://example.org/blog/focus-habits?utm_source=newsletter', 'Rituals that protect long focus blocks.', ['Productivity'], null, ['read', false], 5],
            ['link', 'Brand assets kit', 'https://example.net/brand/assets', 'Logo, color and type tokens.', ['Branding', 'Design'], 'Website Redesign', null, 6],
            ['article', 'Metode penelitian kualitatif', 'https://example.id/riset/kualitatif', 'Panduan singkat wawancara dan observasi.', ['Riset', 'Bahasa Indonesia'], 'Thesis Research', ['unread', true], 8],
            ['link', 'Desk lamp comparison', 'https://example.com/reviews/desk-lamps', 'Color temperature and glare notes.', ['Home'], 'Home Office', null, 9],
            ['article', 'Typography on the web', 'https://example.com/typography-web', 'Line length, rhythm and contrast.', ['Design', 'UI/UX'], null, ['unread', false], 12],
            ['link', 'Café playlist ☕', 'https://example.com/music/cafe', 'Background music for writing.', ['Musik', 'Productivity'], null, null, 14],
            ['article', 'Old research notes', 'https://example.org/notes/archive', 'Superseded literature review.', ['Riset'], 'Thesis Research', ['read', false], 40, 'archived'],
            ['link', 'Deprecated design system', 'https://example.com/legacy-ds', 'Kept for reference.', ['Design'], null, null, 60, 'archived'],
            ['link', 'Broken link to remove', 'https://example.com/broken', 'Moved to trash.', ['Inspiration'], null, null, 20, 'trashed'],
            ['article', 'Inbox: untagged article', 'https://example.com/untagged', null, [], null, ['unread', false], 1],
        ];

        $count = 0;
        foreach ($items as $row) {
            [$type, $title, $url, $description, $tags, $project, $reading, $daysAgo] = $row;
            $state = $row[8] ?? null;
            $n = UrlNormalizer::normalize($url);

            $r = new Resource;
            $r->user_id = $user->id;
            $r->type = ResourceType::from($type);
            $r->title = $title;
            $r->description = $description;
            $r->original_url = $url;
            $r->normalized_url = $n['normalized'];
            $r->normalized_url_hash = $n['hash'];
            $r->source_host = $n['host'];
            $r->project_id = $projects[$project ?? ''] ?? null;
            if ($type === 'article') {
                $r->reading_status = ReadingStatus::from($reading[0]);
                $r->read_at = $reading[0] === 'read' ? now()->subDays($daysAgo - 1) : null;
                $r->read_later = $reading[1];
            }
            $r->metadata_status = MetadataStatus::Ready;
            $r->metadata = ['site_name' => Str::of($n['host'])->replace('www.', '')->toString()];
            $r->personal_notes = $type === 'article' ? "## Why I saved this\n\n- Useful for the **{$title}** topic\n- Revisit next week" : null;
            $r->archived_at = $state === 'archived' ? now()->subDays(3) : null;
            $r->created_at = now()->subDays($daysAgo)->subMinutes($count);
            $r->updated_at = $r->created_at;
            $r->save();
            if ($state === 'trashed') {
                $r->delete();
            }
            if ($tags) {
                $this->tagger->sync($r->id, array_values($this->tags->resolveNames($user, $tags)->pluck('id')->all()));
            }
            $count++;
        }

        // Screenshots generated locally (no network), processed synchronously.
        foreach ([
            ['Dashboard inspiration', [255, 216, 61], [25, 26, 29], ['UI/UX', 'Inspiration'], 'Website Redesign', 4],
            ['Mobile onboarding screens', [255, 116, 23], [255, 248, 239], ['UI/UX'], null, 7],
            ['Workspace mood board', [98, 101, 109], [255, 216, 61], ['Home', 'Inspiration'], 'Home Office', 10],
        ] as [$title, $bg, $fg, $tags, $project, $daysAgo]) {
            $this->screenshot($user, $title, $bg, $fg, $tags, $projects[$project ?? ''] ?? null, $daysAgo);
            $count++;
        }

        return $count;
    }

    /**
     * @param  array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}  $bg
     * @param  array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}  $fg
     * @param  list<string>  $tags
     */
    private function screenshot(User $user, string $title, array $bg, array $fg, array $tags, ?string $projectId, int $daysAgo): void
    {
        $img = imagecreatetruecolor(1200, 800);
        imagefilledrectangle($img, 0, 0, 1200, 800, (int) imagecolorallocate($img, ...$bg));
        $fgc = (int) imagecolorallocate($img, ...$fg);
        imagefilledrectangle($img, 80, 80, 1120, 180, $fgc);
        for ($i = 0; $i < 3; $i++) {
            imagefilledrectangle($img, 80 + $i * 360, 240, 400 + $i * 360, 700, $fgc);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'demo').'.png';
        imagepng($img, $tmp);

        $r = new Resource;
        $r->user_id = $user->id;
        $r->type = ResourceType::Image;
        $r->title = $title;
        $r->project_id = $projectId;
        $r->metadata_status = MetadataStatus::Ready;
        $r->created_at = now()->subDays($daysAgo);
        $r->updated_at = $r->created_at;
        $r->save();

        $key = $this->files->resourceKey($user->id, $r->id, 'original', 'png');
        $this->files->putFile($key, $tmp);
        ResourceFile::query()->create([
            'resource_id' => $r->id, 'user_id' => $user->id, 'role' => FileRole::Original, 'disk' => $this->files->diskName(),
            'storage_key' => $key, 'mime_type' => 'image/png', 'extension' => 'png', 'size_bytes' => filesize($tmp),
            'width' => 1200, 'height' => 800, 'checksum_sha256' => hash_file('sha256', $tmp), 'status' => 'ready',
        ]);

        $variants = $this->images->variants($tmp);
        foreach (['thumbnail' => $variants['thumbnail'], 'preview' => $variants['preview']] as $role => $v) {
            $vkey = $this->files->resourceKey($user->id, $r->id, $role, 'webp');
            $this->files->put($vkey, $v['bytes']);
            ResourceFile::query()->create([
                'resource_id' => $r->id, 'user_id' => $user->id, 'role' => $role, 'disk' => $this->files->diskName(),
                'storage_key' => $vkey, 'mime_type' => 'image/webp', 'extension' => 'webp', 'size_bytes' => strlen($v['bytes']),
                'width' => $v['width'], 'height' => $v['height'], 'checksum_sha256' => hash('sha256', $v['bytes']), 'status' => 'ready',
            ]);
        }
        @unlink($tmp);

        $this->tagger->sync($r->id, array_values($this->tags->resolveNames($user, $tags)->pluck('id')->all()));
    }
}

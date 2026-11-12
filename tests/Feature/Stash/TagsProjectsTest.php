<?php

namespace Tests\Feature\Stash;

use App\Stash\Models\Project;
use App\Stash\Models\Resource;
use App\Stash\Models\Tag;
use App\Stash\Resources\NavigationCounts;
use App\Stash\Resources\ResourceFilters;
use App\Stash\Resources\ResourceQuery;
use App\Stash\Resources\ResourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\StashTesting;
use Tests\TestCase;

/** Spec §14.5 — multi-tags/project/read status survive edits; deletes keep resources. */
class TagsProjectsTest extends TestCase
{
    use RefreshDatabase, StashTesting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStash();
        Queue::fake();
    }

    public function test_tags_project_and_reading_state_survive_unrelated_edits(): void
    {
        $user = $this->owner();
        $project = Project::query()->forceCreate(['user_id' => $user->id, 'name' => 'Website Redesign']);

        $this->actingAs($user)->post('/resources', [
            'url' => 'https://example.com/a', 'type' => 'article', 'tags' => ['UI/UX', 'Inspiration', 'Bahasa Indonesia', 'カフェ'],
            'project_id' => $project->id, 'read_later' => true, 'reading_status' => 'read',
        ])->assertSessionHasNoErrors();
        $r = Resource::query()->firstOrFail();

        $this->actingAs($user)->patch("/resources/{$r->id}", ['title' => 'New title', 'version' => $r->version])->assertSessionHasNoErrors();

        $r = $r->fresh(['tags']);
        $this->assertSame('New title', $r->title);
        $this->assertEqualsCanonicalizing(['UI/UX', 'Inspiration', 'Bahasa Indonesia', 'カフェ'], $r->tags->pluck('name')->all());
        $this->assertSame($project->id, $r->project_id);
        $this->assertTrue($r->read_later);
        $this->assertSame('read', $r->reading_status->value);
        $this->assertNotNull($r->read_at);
        $this->assertSame(2, $r->version);
    }

    public function test_tag_names_are_unique_after_normalization(): void
    {
        $user = $this->owner();
        $service = app(ResourceService::class);
        $service->createFromUrl($user, ['url' => 'https://example.com/1', 'tags' => ['UI/UX', ' ui/ux ', 'Ｕｉ／ux', '#Design', 'design']]);

        $this->assertSame(['design', 'ui/ux'], Tag::query()->orderBy('normalized_name')->pluck('normalized_name')->all());

        // Another owner can have the same tag name.
        $other = $this->owner();
        $service->createFromUrl($other, ['url' => 'https://example.com/1', 'tags' => ['UI/UX']]);
        $this->assertSame(3, Tag::query()->count());

        $this->actingAs($user)->post('/tags', ['name' => 'design'])->assertSessionHasErrors('name');
        $this->actingAs($user)->post('/tags', ['name' => 'Café ☕'])->assertSessionHasNoErrors();
    }

    public function test_deleting_a_tag_removes_associations_only_and_counts_stay_consistent(): void
    {
        $user = $this->owner();
        $service = app(ResourceService::class);
        $a = $service->createFromUrl($user, ['url' => 'https://example.com/a', 'tags' => ['shared', 'alpha']])['resource'];
        $b = $service->createFromUrl($user, ['url' => 'https://example.com/b', 'tags' => ['shared']])['resource'];
        $tag = Tag::query()->where('normalized_name', 'shared')->firstOrFail();

        $this->assertSame(2, $this->tagCount($user, 'shared'));
        $this->actingAs($user)->delete("/tags/{$tag->id}")->assertRedirect();

        $this->assertSame(2, Resource::query()->count(), 'resources are kept');
        $this->assertSame('alpha', $a->fresh()->tag_names);
        $this->assertSame('', $b->fresh()->tag_names);
        $this->assertSame(2, $a->fresh()->version, 'tag removal bumps the version');
        $this->assertSame(1, app(NavigationCounts::class)->sections($user)['inbox'], 'b is now untagged and unassigned');
        $this->assertSame(0, $this->search($user, 'shared'));
    }

    public function test_renaming_a_tag_updates_search(): void
    {
        $user = $this->owner();
        app(ResourceService::class)->createFromUrl($user, ['url' => 'https://example.com/a', 'title' => 'Some page', 'tags' => ['typo-tagg']]);
        $tag = Tag::query()->firstOrFail();

        $this->actingAs($user)->patch("/tags/{$tag->id}", ['name' => 'Typography'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->search($user, 'typography'));
        $this->assertSame(0, $this->search($user, 'tagg'));
    }

    public function test_deleting_a_project_unassigns_resources_transactionally(): void
    {
        $user = $this->owner();
        $project = Project::query()->forceCreate(['user_id' => $user->id, 'name' => 'Thesis']);
        $service = app(ResourceService::class);
        $r1 = $service->createFromUrl($user, ['url' => 'https://example.com/1', 'project_id' => $project->id])['resource'];
        $service->createFromUrl($user, ['url' => 'https://example.com/2', 'project_id' => $project->id])['resource']->delete();

        $this->assertSame(1, collect(app(NavigationCounts::class)->projects($user))->firstWhere('id', $project->id)['count']);
        $this->actingAs($user)->delete("/projects/{$project->id}")->assertRedirect();

        $this->assertNull(Project::query()->find($project->id));
        $this->assertSame(2, Resource::withTrashed()->whereNull('project_id')->count(), 'trashed resources are unassigned too');
        $this->assertSame(2, $r1->fresh()->version);
        $this->assertSame(1, app(NavigationCounts::class)->sections($user)['inbox']);
    }

    public function test_project_assignment_is_ownership_scoped(): void
    {
        $user = $this->owner();
        $other = $this->owner();
        $foreign = Project::query()->forceCreate(['user_id' => $other->id, 'name' => 'Not yours']);
        $r = $this->resource($user);

        $this->actingAs($user)->patch("/resources/{$r->id}", ['project_id' => $foreign->id])->assertSessionHasErrors('project_id');
        $this->assertNull($r->fresh()->project_id);

        $this->actingAs($user)->post('/resources/bulk', ['operation' => 'set_project', 'ids' => [$r->id], 'project_id' => $foreign->id])
            ->assertSessionHasErrors('project_id');
        $this->actingAs($user)->patch("/projects/{$foreign->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->assertSame('Not yours', $foreign->fresh()->name);
    }

    private function tagCount($user, string $name): int
    {
        return collect(app(NavigationCounts::class)->tags($user))->firstWhere('name', $name)['count'] ?? 0;
    }

    private function search($user, string $q): int
    {
        return app(ResourceQuery::class)->paginate($user, ResourceFilters::fromArray(['q' => $q]))->total();
    }
}

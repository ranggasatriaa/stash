<?php

namespace Tests\Feature\Stash;

use App\Models\User;
use App\Stash\Enums\Section;
use App\Stash\Models\Resource;
use App\Stash\Models\Tag;
use App\Stash\Resources\NavigationCounts;
use App\Stash\Resources\ResourceFilters;
use App\Stash\Resources\ResourceQuery;
use App\Stash\Resources\ResourceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\StashTesting;
use Tests\TestCase;

/** Spec §14.6 — search fields, AND filters, tag Any/All, timezone dates, stable sort, exclusion rules. */
class SearchFilterTest extends TestCase
{
    use RefreshDatabase, StashTesting;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStash();
        Queue::fake();
        $this->user = $this->owner(['timezone' => 'Asia/Jakarta']);
    }

    private function make(array $data): Resource
    {
        return app(ResourceService::class)->createFromUrl($this->user, $data + ['url' => 'https://example.com/'.uniqid()])['resource'];
    }

    /** @return list<string> titles */
    private function titles(array $filters, Section $section = Section::All): array
    {
        $page = app(ResourceQuery::class)->paginate($this->user, ResourceFilters::fromArray($filters, $section));

        return array_map(fn ($r) => $r->title, $page->items());
    }

    public function test_search_matches_title_description_url_tags_and_notes(): void
    {
        $this->make(['title' => 'Checkout flow reference', 'url' => 'https://flow.design/checkout']);
        $this->make(['title' => 'Untitled A', 'description' => 'Payment edge cases for wallets']);
        $this->make(['title' => 'Untitled B', 'url' => 'https://docs.example.org/kubernetes-guide']);
        $this->make(['title' => 'Untitled C', 'tags' => ['Typography']]);
        $this->make(['title' => 'Untitled D', 'personal_notes' => 'remember the zeppelin idea']);

        $this->assertSame(['Checkout flow reference'], $this->titles(['q' => 'checkout']));
        $this->assertSame(['Untitled A'], $this->titles(['q' => 'wallets']));
        $this->assertSame(['Untitled B'], $this->titles(['q' => 'kubernetes']));
        $this->assertSame(['Untitled C'], $this->titles(['q' => 'typography']));
        $this->assertSame(['Untitled D'], $this->titles(['q' => 'zeppelin']));
        $this->assertSame(['Untitled C'], $this->titles(['q' => 'typograhpy']), 'fuzzy trigram match tolerates a typo');
        $this->assertSame(['Checkout flow reference'], $this->titles(['q' => 'ch']), 'short queries use prefix strategies');
    }

    public function test_relevance_ranks_exact_and_prefix_title_and_tag_matches_first(): void
    {
        $this->make(['title' => 'A design article', 'description' => 'mentions design in passing']);
        $this->make(['title' => 'Something else', 'tags' => ['Design']]);
        $this->make(['title' => 'Design']);
        $this->make(['title' => 'Designing calm interfaces']);

        $this->assertSame(['Design', 'Something else', 'Designing calm interfaces', 'A design article'], $this->titles(['q' => 'design']));
    }

    public function test_like_wildcards_and_tsquery_syntax_are_treated_literally(): void
    {
        $this->make(['title' => 'Growth 100% organic']);
        $this->make(['title' => 'Unrelated thing']);

        $this->assertSame(['Growth 100% organic'], $this->titles(['q' => '100%']));
        $this->assertSame([], $this->titles(['q' => '%_%']));
        $this->assertSame([], $this->titles(['q' => "') | !foo & (bar:*"]));
    }

    public function test_filters_are_anded_and_tag_any_all_modes(): void
    {
        $a = $this->make(['title' => 'A', 'type' => 'article', 'tags' => ['ux', 'research']]);
        $this->make(['title' => 'B', 'type' => 'link', 'tags' => ['ux']]);
        $this->make(['title' => 'C', 'type' => 'article', 'tags' => ['research']]);
        $this->make(['title' => 'D', 'type' => 'article']);
        $ux = Tag::query()->where('normalized_name', 'ux')->value('id');
        $research = Tag::query()->where('normalized_name', 'research')->value('id');

        $this->assertEqualsCanonicalizing(['A', 'B', 'C'], $this->titles(['tags' => [$ux, $research]]));
        $this->assertSame(['A'], $this->titles(['tags' => [$ux, $research], 'tag_mode' => 'all']));
        $this->assertEqualsCanonicalizing(['A', 'C'], $this->titles(['tags' => [$ux, $research], 'type' => ['article']]));
        $this->assertSame(['D'], $this->titles(['project' => 'none', 'type' => ['article'], 'q' => 'D']));

        // A resource matching several tags appears once.
        $page = app(ResourceQuery::class)->paginate($this->user, ResourceFilters::fromArray(['tags' => [$ux, $research]]));
        $this->assertSame(3, $page->total());
        $this->assertSame(count(array_unique(array_map(fn ($r) => $r->id, $page->items()))), count($page->items()));

        app(ResourceService::class)->update($this->user, $a, ['reading_status' => 'read']);
        $this->assertSame(['A'], $this->titles(['reading' => 'read']));
        $this->assertEqualsCanonicalizing(['C', 'D'], $this->titles(['reading' => 'unread']), 'reading filter matches articles only');
    }

    public function test_date_range_uses_the_user_timezone_with_inclusive_end(): void
    {
        // 2026-03-01 17:30 UTC is 2026-03-02 00:30 in Asia/Jakarta (UTC+7).
        $late = $this->make(['title' => 'Late night']);
        $late->forceFill(['created_at' => CarbonImmutable::parse('2026-03-01 17:30:00', 'UTC')])->save();
        // 2026-03-02 16:59 UTC is 2026-03-02 23:59 in Jakarta: still the 2nd.
        $edge = $this->make(['title' => 'Edge of day']);
        $edge->forceFill(['created_at' => CarbonImmutable::parse('2026-03-02 16:59:00', 'UTC')])->save();
        // 2026-03-02 17:00 UTC is 2026-03-03 00:00 in Jakarta.
        $next = $this->make(['title' => 'Next day']);
        $next->forceFill(['created_at' => CarbonImmutable::parse('2026-03-02 17:00:00', 'UTC')])->save();

        $this->assertEqualsCanonicalizing(['Late night', 'Edge of day'], $this->titles(['from' => '2026-03-02', 'to' => '2026-03-02']));
        $this->assertSame([], $this->titles(['to' => '2026-03-01', 'from' => '2026-03-01']));
        $this->assertSame(['Next day'], $this->titles(['from' => '2026-03-03']));
    }

    public function test_sorting_is_stable_and_pagination_neither_skips_nor_repeats(): void
    {
        $when = CarbonImmutable::parse('2026-01-01 10:00:00');
        for ($i = 0; $i < 30; $i++) {
            $r = $this->make(['title' => 'Same title']);
            $r->forceFill(['created_at' => $when])->save();
        }

        foreach (['newest', 'oldest', 'title'] as $sort) {
            $seen = [];
            for ($page = 1; $page <= 3; $page++) {
                $items = app(ResourceQuery::class)->paginate($this->user, ResourceFilters::fromArray(['sort' => $sort, 'per_page' => 12, 'page' => $page]))->items();
                foreach ($items as $r) {
                    $seen[] = $r->id;
                }
            }
            $this->assertCount(30, $seen, $sort);
            $this->assertCount(30, array_unique($seen), "no duplicates across pages ({$sort})");
        }
    }

    public function test_archived_and_trashed_resources_are_excluded_from_active_views_and_counts_match(): void
    {
        $service = app(ResourceService::class);
        $this->make(['title' => 'Active link']);
        $article = $this->make(['title' => 'Active article', 'type' => 'article', 'read_later' => true]);
        $archived = $this->make(['title' => 'Archived one']);
        $service->archive($this->user, $archived);
        $trashed = $this->make(['title' => 'Trashed one']);
        $service->trash($this->user, $trashed);
        $both = $this->make(['title' => 'Archived then trashed']);
        $service->archive($this->user, $both);
        $service->trash($this->user, $both);

        $this->assertEqualsCanonicalizing(['Active link', 'Active article'], $this->titles([]));
        $this->assertSame([], $this->titles(['q' => 'archived']), 'search respects the section');
        $this->assertSame(['Archived one'], $this->titles([], Section::Archive), 'archive excludes trash');
        $this->assertEqualsCanonicalizing(['Trashed one', 'Archived then trashed'], $this->titles([], Section::Trash));
        $this->assertSame(['Active article'], $this->titles([], Section::ReadLater));

        $counts = app(NavigationCounts::class)->sections($this->user);
        foreach ([Section::All, Section::Archive, Section::Trash, Section::ReadLater, Section::Bookmarks, Section::Inbox, Section::Screenshots] as $section) {
            $this->assertSame($counts[$section->value], count($this->titles(['per_page' => 100], $section)), "count for {$section->value}");
        }

        $this->actingAs($this->user)->get('/library/archive')->assertInertia(fn ($p) => $p->where('resources.meta.total', 1));
        $this->assertSame(1, $service->reload($article)->version);
    }

    public function test_query_parameters_drive_the_web_view_and_relevance_default(): void
    {
        $this->make(['title' => 'Alpha design', 'type' => 'article']);
        $this->make(['title' => 'Beta']);

        $this->actingAs($this->user)->get('/library?q=design&type[]=article')
            ->assertInertia(fn ($p) => $p->component('library/index')
                ->where('filters.q', 'design')
                ->where('filters.type', ['article'])
                ->where('filters.sort_effective', 'relevance')
                ->where('resources.meta.total', 1));

        $this->actingAs($this->user)->getJson('/api/v1/resources?q=beta', $this->bearer($this->apiToken($this->user, ['resources:read'])))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.title', 'Beta');
    }
}

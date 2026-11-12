<?php

namespace Tests\Feature\Stash;

use App\Stash\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\StashTesting;
use Tests\TestCase;

/** The OpenAPI contract documents every /api/v1 route, and the CRUD flows behave as documented. */
class ApiContractTest extends TestCase
{
    use RefreshDatabase, StashTesting;

    public function test_openapi_document_covers_every_api_route(): void
    {
        $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));
        $this->assertSame('3.1.0', $spec['openapi']);
        $documented = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (in_array($method, ['get', 'post', 'patch', 'put', 'delete'], true)) {
                    $documented[] = strtoupper($method).' '.preg_replace('/\{[^}]+\}/', '{}', $path);
                }
            }
        }

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $path = preg_replace('/\{[^}]+\}/', '{}', substr($route->uri(), strlen('api/v1')));
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->assertContains("{$method} {$path}", $documented, "Undocumented API route: {$method} {$path}");
            }
        }

        $tools = array_column($spec['x-agent-tools']['tools'], 'name');
        foreach (['search_resources', 'create_resource', 'update_resource', 'assign_tags', 'assign_project', 'summarize_resource', 'archive_resource'] as $tool) {
            $this->assertContains($tool, $tools);
        }
        $this->assertNotContains('delete_resource_permanently', $tools);
    }

    public function test_tags_and_projects_crud_over_the_api(): void
    {
        Queue::fake();
        $this->setUpStash();
        $user = $this->owner();
        $h = $this->bearer($this->apiToken($user, ['tags:read', 'tags:write', 'projects:read', 'projects:write', 'resources:read', 'resources:write']));

        $tag = $this->withHeaders($h)->postJson('/api/v1/tags', ['name' => 'Research'])->assertCreated()->json('data.id');
        $this->withHeaders($h)->postJson('/api/v1/tags', ['name' => ' research '])->assertStatus(422)->assertJsonPath('error.code', 'tag_exists');
        $this->withHeaders($h)->patchJson("/api/v1/tags/{$tag}", ['name' => 'Riset'])->assertOk()->assertJsonPath('data.name', 'Riset');

        $project = $this->withHeaders($h)->postJson('/api/v1/projects', ['name' => 'Thesis'])->assertCreated()->json('data.id');
        $resource = $this->withHeaders($h)->postJson('/api/v1/resources', ['url' => 'https://example.com/a', 'tags' => ['Riset'], 'project_id' => $project])
            ->assertCreated()->json('data');
        $this->assertSame('queued', $resource['processing']['status']);
        $this->assertSame($project, $resource['project_id']);

        $this->withHeaders($h)->getJson('/api/v1/tags')->assertOk()->assertJsonPath('data.0.count', 1);
        $this->withHeaders($h)->getJson("/api/v1/resources?project={$project}")->assertJsonPath('meta.total', 1);

        $this->withHeaders($h)->deleteJson("/api/v1/projects/{$project}")->assertOk()->assertJsonPath('data.unassigned_resources', 1);
        $this->withHeaders($h)->deleteJson("/api/v1/tags/{$tag}")->assertOk()->assertJsonPath('data.detached_resources', 1);
        $this->withHeaders($h)->getJson("/api/v1/resources/{$resource['id']}")->assertOk()
            ->assertJsonPath('data.tags', [])->assertJsonPath('data.project_id', null)->assertJsonPath('data.version', 3);
        $this->assertSame(0, Project::query()->count());
    }

    public function test_validation_errors_use_the_envelope_with_request_ids(): void
    {
        $user = $this->owner();
        $h = $this->bearer($this->apiToken($user, ['resources:write'])) + ['X-Request-Id' => 'req-12345678'];

        $this->withHeaders($h)->postJson('/api/v1/resources', ['url' => 'not a url'])
            ->assertStatus(422)
            ->assertHeader('X-Request-Id', 'req-12345678')
            ->assertJsonPath('error.code', 'invalid_url')
            ->assertJsonPath('error.request_id', 'req-12345678');

        $this->withHeaders($h)->postJson('/api/v1/resources', [])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['url']]]]);
    }
}

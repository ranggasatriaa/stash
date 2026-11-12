<?php

namespace Tests\Feature\Stash;

use App\Models\User;
use App\Stash\Models\Resource;
use App\Stash\Models\ResourceFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\StashTesting;
use Tests\TestCase;

/** Spec §14.1 — owner-only access, file isolation, token abilities, no registration. */
class AuthIsolationTest extends TestCase
{
    use RefreshDatabase, StashTesting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStash();
    }

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'x', 'email' => 'x@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123'])
            ->assertStatus(404);
        $this->get('/forgot-password')->assertNotFound();
        $this->assertSame(0, User::query()->count());
    }

    public function test_guests_are_redirected_and_api_requires_a_token(): void
    {
        $this->get('/library')->assertRedirect('/login');
        $this->getJson('/api/v1/resources')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_records_are_isolated_between_owners_on_web_and_api(): void
    {
        $alice = $this->owner();
        $bob = $this->owner();
        $resource = $this->resource($alice, ['title' => 'Alice secret']);

        // Web: foreign records look like they do not exist.
        $this->actingAs($bob)->patch("/resources/{$resource->id}", ['title' => 'pwned'])->assertNotFound();
        $this->actingAs($bob)->delete("/resources/{$resource->id}")->assertNotFound();
        $this->actingAs($bob)->get("/library?resource={$resource->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('selected', null));
        $this->actingAs($bob)->get('/library')->assertInertia(fn ($page) => $page->where('resources.meta.total', 0));

        // API: token scopes never bypass ownership.
        $token = $this->apiToken($bob, ['resources:read', 'resources:write']);
        $this->withHeaders($this->bearer($token))->getJson("/api/v1/resources/{$resource->id}")->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->withHeaders($this->bearer($token))->patchJson("/api/v1/resources/{$resource->id}", ['title' => 'x', 'version' => 1])->assertNotFound();

        $this->assertSame('Alice secret', $resource->fresh()->title);
    }

    public function test_files_are_private_and_owner_only(): void
    {
        Queue::fake();
        $alice = $this->owner();
        $bob = $this->owner();

        $this->actingAs($alice)->post('/resources', ['file' => $this->uploadedPng()])->assertRedirect();
        $file = ResourceFile::query()->firstOrFail();

        // Stored on the private disk, outside public/.
        $this->assertStringStartsWith("users/{$alice->id}/resources/", $file->storage_key);
        $this->assertFileDoesNotExist(public_path($file->storage_key));

        auth()->guard('web')->logout();
        $this->get("/files/{$file->id}")->assertRedirect('/login');
        $this->actingAs($bob)->get("/files/{$file->id}")->assertNotFound();

        $response = $this->actingAs($alice)->get("/files/{$file->id}");
        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));

        $bobToken = $this->apiToken($bob, ['resources:read']);
        $this->withHeaders($this->bearer($bobToken))->get("/api/v1/files/{$file->id}")->assertNotFound();
    }

    public function test_token_abilities_and_revocation_are_enforced(): void
    {
        $alice = $this->owner();
        $readOnly = $this->apiToken($alice, ['resources:read']);

        $this->withHeaders($this->bearer($readOnly))->getJson('/api/v1/resources')->assertOk();
        $this->withHeaders($this->bearer($readOnly))->postJson('/api/v1/resources', ['url' => 'https://example.com'])
            ->assertForbidden()->assertJsonPath('error.code', 'missing_ability');
        $this->withHeaders($this->bearer($readOnly))->getJson('/api/v1/tags')->assertForbidden();

        // Permanent deletion needs its own privileged ability.
        $writer = $this->apiToken($alice, ['resources:read', 'resources:write']);
        $r = $this->resource($alice);
        $r->delete();
        $this->withHeaders($this->bearer($writer))->deleteJson("/api/v1/resources/{$r->id}/permanent", ['confirm' => true])->assertForbidden();

        // Expired tokens are rejected.
        $this->app['auth']->forgetGuards();
        $expired = $this->apiToken($alice, ['*'], now()->subMinute());
        $this->withHeaders($this->bearer($expired))->getJson('/api/v1/resources')->assertUnauthorized();

        // Revoked tokens are rejected (fresh app instance per request is simulated by forgetting guards).
        $alice->tokens()->delete();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->bearer($readOnly))->getJson('/api/v1/resources')->assertUnauthorized();
    }

    public function test_tokens_are_created_and_revoked_from_the_ui_and_shown_once(): void
    {
        $alice = $this->owner();
        $this->actingAs($alice)->withSession(['auth.password_confirmed_at' => time()]);

        $this->post('/settings/tokens', ['name' => 'Agent', 'abilities' => ['resources:read', 'tags:read'], 'expires_in_days' => 30])
            ->assertRedirect();
        $token = $alice->tokens()->firstOrFail();
        $this->assertSame(['resources:read', 'tags:read'], $token->abilities);
        $this->assertNotNull($token->expires_at);

        // Plain text is only flashed; never in the token listing.
        $this->get('/settings/tokens')->assertInertia(fn ($page) => $page->has('tokens', 1)->missing('tokens.0.token'));

        $this->delete("/settings/tokens/{$token->id}")->assertRedirect();
        $this->assertSame(0, $alice->tokens()->count());

        // Unknown abilities are rejected.
        $this->post('/settings/tokens', ['name' => 'Bad', 'abilities' => ['admin:*']])->assertSessionHasErrors('abilities.0');
    }

    public function test_owner_is_created_by_cli_only_once(): void
    {
        putenv('STASH_TEST_OWNER_PASSWORD=Very-long-Passw0rd!');
        $code = Artisan::call('stash:create-owner', [
            '--name' => 'Owner', '--email' => 'Owner@Example.com', '--password-from-env' => 'STASH_TEST_OWNER_PASSWORD', '--timezone' => 'Asia/Jakarta',
        ]);
        $this->assertSame(0, $code);
        $owner = User::query()->firstOrFail();
        $this->assertSame('owner@example.com', $owner->email);
        $this->assertSame('Asia/Jakarta', $owner->timezone);
        $this->assertNotSame('Very-long-Passw0rd!', $owner->password);

        $this->assertSame(1, Artisan::call('stash:create-owner', ['--name' => 'Second', '--email' => 'b@example.com', '--password-from-env' => 'STASH_TEST_OWNER_PASSWORD']));
        $this->assertSame(1, User::query()->count());

        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'Very-long-Passw0rd!'])->assertRedirect('/library');
        putenv('STASH_TEST_OWNER_PASSWORD');
    }

    public function test_demo_seed_is_refused_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->assertSame(1, Artisan::call('stash:demo-seed'));
        $this->assertSame(0, Resource::withTrashed()->count());
    }
}

<?php

// ─── ChatSearchTest ────────────────────────────────────────────────────────────
// Tests GET /chat/users/search?q={term}:
//   - Returns partial-match results (name contains query)
//   - Scoped to the authenticated user's tenant (no cross-tenant leakage)
//   - Minimum 2 characters enforced (< 2 → empty array)
//   - Excludes the searching user themselves
//   - Max 20 results
//   - Requires authentication (401 if unauthenticated)
// ──────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatSearchTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $attrs = []): User
    {
        $tenant = Tenant::factory()->create();
        return User::factory()->create(array_merge(['tenant_id' => $tenant->id, 'is_active' => true], $attrs));
    }

    // ── Authentication ────────────────────────────────────────────────────────

    public function test_search_requires_authentication(): void
    {
        $this->getJson('/chat/users/search?q=mar')->assertUnauthorized();
    }

    // ── Min-length enforcement ────────────────────────────────────────────────

    public function test_search_returns_empty_for_single_char(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)
            ->getJson('/chat/users/search?q=m')
            ->assertOk()
            ->assertJson(['users' => []]);
    }

    public function test_search_returns_empty_for_missing_query(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)
            ->getJson('/chat/users/search')
            ->assertOk()
            ->assertJson(['users' => []]);
    }

    // ── Partial match ─────────────────────────────────────────────────────────

    public function test_search_returns_partial_first_name_match(): void
    {
        $tenant = Tenant::factory()->create();
        $searcher = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Alice', 'last_name' => 'Admin',   'is_active' => true]);
        $margaret = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Margaret', 'last_name' => 'Demo', 'is_active' => true]);
        $maria    = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Maria',    'last_name' => 'Demo', 'is_active' => true]);
        User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Bob', 'last_name' => 'Demo', 'is_active' => true]);

        $response = $this->actingAs($searcher)->getJson('/chat/users/search?q=mar');

        $response->assertOk();
        $names = collect($response->json('users'))->pluck('name')->all();
        $this->assertContains('Margaret Demo', $names);
        $this->assertContains('Maria Demo', $names);
        $this->assertNotContains('Bob Demo', $names);
    }

    public function test_search_is_case_insensitive(): void
    {
        $tenant   = Tenant::factory()->create();
        $searcher = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Alice',   'last_name' => 'X', 'is_active' => true]);
        $target   = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'CARLOS',  'last_name' => 'Demo', 'is_active' => true]);

        $response = $this->actingAs($searcher)->getJson('/chat/users/search?q=carlos');
        $names = collect($response->json('users'))->pluck('name')->all();
        $this->assertContains('CARLOS Demo', $names);
    }

    public function test_search_matches_last_name(): void
    {
        $tenant   = Tenant::factory()->create();
        $searcher = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Alice',   'last_name' => 'X', 'is_active' => true]);
        $target   = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Bob',     'last_name' => 'Martinez', 'is_active' => true]);

        $response = $this->actingAs($searcher)->getJson('/chat/users/search?q=mart');
        $names = collect($response->json('users'))->pluck('name')->all();
        $this->assertContains('Bob Martinez', $names);
    }

    // ── Tenant scoping ────────────────────────────────────────────────────────

    public function test_search_excludes_cross_tenant_users(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $searcher = User::factory()->create(['tenant_id' => $tenantA->id, 'first_name' => 'Alice', 'last_name' => 'X', 'is_active' => true]);
        User::factory()->create(['tenant_id' => $tenantB->id, 'first_name' => 'Marcus', 'last_name' => 'OtherTenant', 'is_active' => true]);

        $response = $this->actingAs($searcher)->getJson('/chat/users/search?q=mar');
        $names = collect($response->json('users'))->pluck('name')->all();
        $this->assertNotContains('Marcus OtherTenant', $names);
    }

    // ── Excludes self ─────────────────────────────────────────────────────────

    public function test_search_excludes_self(): void
    {
        $tenant  = Tenant::factory()->create();
        $searcher = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Marcus', 'last_name' => 'Self', 'is_active' => true]);

        $response = $this->actingAs($searcher)->getJson('/chat/users/search?q=mar');
        $ids = collect($response->json('users'))->pluck('id')->all();
        $this->assertNotContains($searcher->id, $ids);
    }

    // ── Response shape ────────────────────────────────────────────────────────

    public function test_search_response_includes_required_fields(): void
    {
        $tenant   = Tenant::factory()->create();
        $searcher = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Alice', 'last_name' => 'X', 'is_active' => true]);
        User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Maria', 'last_name' => 'Demo', 'is_active' => true]);

        $response = $this->actingAs($searcher)->getJson('/chat/users/search?q=mar');
        $response->assertOk()->assertJsonStructure([
            'users' => [['id', 'name', 'department', 'role']],
        ]);
    }

    // ── Inactive users excluded ───────────────────────────────────────────────

    public function test_search_excludes_inactive_users(): void
    {
        $tenant   = Tenant::factory()->create();
        $searcher = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Alice', 'last_name' => 'X', 'is_active' => true]);
        User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Martha', 'last_name' => 'Inactive', 'is_active' => false]);

        $response = $this->actingAs($searcher)->getJson('/chat/users/search?q=mar');
        $names = collect($response->json('users'))->pluck('name')->all();
        $this->assertNotContains('Martha Inactive', $names);
    }
}

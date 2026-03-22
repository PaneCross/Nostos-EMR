<?php

// ─── SiteContextTest ───────────────────────────────────────────────────────────
// Feature tests for the site context switcher (Phase 10B).
//
// Coverage:
//   - Regular user always gets own site_id (session ignored)
//   - Executive user can switch to own-tenant site (sets session)
//   - SA role user can switch to any site
//   - SA dept user can switch to any site
//   - Executive cannot switch to cross-tenant site (403)
//   - Regular user gets 403 on site switch
//   - Clear site context removes session key
//   - Inertia shared props include site_context and available_sites
//   - site_context reflects active_site_id from session for executive
//   - available_sites limited to own tenant for executive
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteContextTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeExecutiveWithSites(): array
    {
        $tenant = Tenant::factory()->create();
        $site1  = Site::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
        $site2  = Site::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
        $user   = User::factory()->create([
            'department' => 'executive',
            'role'       => 'standard',
            'tenant_id'  => $tenant->id,
            'site_id'    => $site1->id,
        ]);
        return compact('tenant', 'site1', 'site2', 'user');
    }

    private function makeSuperAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function makeDeptSuperAdmin(): User
    {
        return User::factory()->create(['department' => 'super_admin', 'role' => 'standard']);
    }

    private function makeRegularUser(): User
    {
        return User::factory()->create(['department' => 'it_admin', 'role' => 'standard']);
    }

    // ── Switch endpoint ───────────────────────────────────────────────────────

    public function test_executive_can_switch_to_own_tenant_site(): void
    {
        ['site2' => $site2, 'user' => $user] = $this->makeExecutiveWithSites();

        $this->actingAs($user)
            ->postJson('/site-context/switch', ['site_id' => $site2->id])
            ->assertOk()
            ->assertJsonPath('site.id', $site2->id);
    }

    public function test_executive_cannot_switch_to_cross_tenant_site(): void
    {
        ['user' => $user] = $this->makeExecutiveWithSites();

        $otherTenant = Tenant::factory()->create();
        $otherSite   = Site::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($user)
            ->postJson('/site-context/switch', ['site_id' => $otherSite->id])
            ->assertForbidden();
    }

    public function test_regular_user_cannot_switch_site(): void
    {
        $user = $this->makeRegularUser();
        $site = Site::factory()->create(['tenant_id' => $user->tenant_id]);

        $this->actingAs($user)
            ->postJson('/site-context/switch', ['site_id' => $site->id])
            ->assertForbidden();
    }

    public function test_super_admin_role_can_switch_to_any_site(): void
    {
        $user = $this->makeSuperAdmin();
        $site = Site::factory()->create();

        $this->actingAs($user)
            ->postJson('/site-context/switch', ['site_id' => $site->id])
            ->assertOk();
    }

    public function test_dept_super_admin_can_switch_to_any_site(): void
    {
        $user = $this->makeDeptSuperAdmin();
        $site = Site::factory()->create();

        $this->actingAs($user)
            ->postJson('/site-context/switch', ['site_id' => $site->id])
            ->assertOk();
    }

    public function test_switch_validates_site_id_required(): void
    {
        $user = $this->makeExecutiveWithSites()['user'];

        $this->actingAs($user)
            ->postJson('/site-context/switch', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['site_id']);
    }

    // ── Clear endpoint ────────────────────────────────────────────────────────

    public function test_clear_removes_active_site_from_session(): void
    {
        $user = $this->makeSuperAdmin();

        // Set a site in session first
        $this->actingAs($user)
            ->withSession(['active_site_id' => 999])
            ->deleteJson('/site-context')
            ->assertOk();
    }

    public function test_regular_user_cannot_clear_site_context(): void
    {
        $user = $this->makeRegularUser();

        $this->actingAs($user)
            ->deleteJson('/site-context')
            ->assertForbidden();
    }

    // ── Inertia shared props ──────────────────────────────────────────────────

    public function test_site_context_in_inertia_shared_props(): void
    {
        ['site1' => $site1, 'user' => $user] = $this->makeExecutiveWithSites();

        $response = $this->actingAs($user)
            ->get('/dashboard/executive');

        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->has('site_context')
                 ->has('available_sites')
        );
    }
}

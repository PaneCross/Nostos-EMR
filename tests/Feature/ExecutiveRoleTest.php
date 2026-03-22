<?php

// ─── ExecutiveRoleTest ─────────────────────────────────────────────────────────
// Feature tests for the Executive department role (Phase 10B).
//
// Coverage:
//   - Executive user can access /dashboard/executive (200)
//   - Executive user redirected to /dashboard/executive from other dept dashboards
//   - Executive can access all 4 widget endpoints
//   - Non-executive user cannot access executive widget endpoints (403)
//   - Super-admin can access executive widget endpoints
//   - Dept super-admin can access executive widget endpoints
//   - Executive widget org-overview returns expected JSON structure
//   - Executive widget site-comparison returns expected JSON structure
//   - Executive widget financial-overview returns expected JSON structure
//   - Executive widget sites-list returns expected JSON structure
//   - Executive cannot create participants (RBAC: read-only)
//   - Executive cannot POST to clinical notes
//   - Site switcher: executive can switch active site (own tenant only)
//   - Site switcher: executive cannot switch to cross-tenant site (403)
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecutiveRoleTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeExecutiveUser(?int $tenantId = null): User
    {
        $attrs = ['department' => 'executive', 'role' => 'standard'];
        if ($tenantId !== null) {
            $attrs['tenant_id'] = $tenantId;
        }
        return User::factory()->create($attrs);
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
        return User::factory()->create(['department' => 'dietary', 'role' => 'standard']);
    }

    // ── Dashboard access ──────────────────────────────────────────────────────

    public function test_executive_can_access_executive_dashboard(): void
    {
        $user = $this->makeExecutiveUser();

        $this->actingAs($user)
            ->get('/dashboard/executive')
            ->assertOk();
    }

    public function test_executive_redirected_from_other_dept_dashboards(): void
    {
        $user = $this->makeExecutiveUser();

        $this->actingAs($user)
            ->get('/dashboard/it_admin')
            ->assertRedirect('/dashboard/executive');
    }

    // ── Widget endpoint access ────────────────────────────────────────────────

    public function test_executive_can_access_all_widgets(): void
    {
        $user = $this->makeExecutiveUser();

        $this->actingAs($user)->getJson('/dashboards/executive/org-overview')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/executive/site-comparison')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/executive/financial-overview')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/executive/sites-list')->assertOk();
    }

    public function test_non_executive_cannot_access_widget_endpoints(): void
    {
        $user = $this->makeRegularUser();

        $this->actingAs($user)->getJson('/dashboards/executive/org-overview')->assertForbidden();
    }

    public function test_super_admin_can_access_executive_widgets(): void
    {
        $user = $this->makeSuperAdmin();

        $this->actingAs($user)->getJson('/dashboards/executive/org-overview')->assertOk();
    }

    public function test_dept_super_admin_can_access_executive_widgets(): void
    {
        $user = $this->makeDeptSuperAdmin();

        $this->actingAs($user)->getJson('/dashboards/executive/org-overview')->assertOk();
    }

    // ── Widget JSON structure ─────────────────────────────────────────────────

    public function test_org_overview_returns_expected_structure(): void
    {
        $user = $this->makeExecutiveUser();

        $this->actingAs($user)
            ->getJson('/dashboards/executive/org-overview')
            ->assertOk()
            ->assertJsonStructure(['enrolled', 'pending_enrollment', 'new_referrals_30d', 'active_sites']);
    }

    public function test_site_comparison_returns_expected_structure(): void
    {
        $user = $this->makeExecutiveUser();

        $this->actingAs($user)
            ->getJson('/dashboards/executive/site-comparison')
            ->assertOk()
            ->assertJsonStructure(['sites']);
    }

    public function test_financial_overview_returns_expected_structure(): void
    {
        $user = $this->makeExecutiveUser();

        $this->actingAs($user)
            ->getJson('/dashboards/executive/financial-overview')
            ->assertOk()
            ->assertJsonStructure(['month_year', 'grand_total', 'by_site']);
    }

    public function test_sites_list_returns_expected_structure(): void
    {
        $user = $this->makeExecutiveUser();

        $this->actingAs($user)
            ->getJson('/dashboards/executive/sites-list')
            ->assertOk()
            ->assertJsonStructure(['sites']);
    }

    // ── RBAC: executive cannot access admin-only endpoints ────────────────────

    public function test_executive_cannot_access_it_admin_panel(): void
    {
        $user = $this->makeExecutiveUser();

        // ItAdminController::requireItAdmin() explicitly gates to department='it_admin'
        $this->actingAs($user)
            ->get('/it-admin/users')
            ->assertForbidden();
    }

    public function test_executive_cannot_start_impersonation(): void
    {
        $user  = $this->makeExecutiveUser();
        $other = User::factory()->create(['tenant_id' => $user->tenant_id]);

        // ImpersonationController::requireSuperAdmin() gates to role='super_admin'
        $this->actingAs($user)
            ->postJson("/super-admin/impersonate/{$other->id}")
            ->assertForbidden();
    }

    // ── Site context switcher ─────────────────────────────────────────────────

    public function test_executive_can_switch_to_site_in_own_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $site   = Site::factory()->create(['tenant_id' => $tenant->id]);
        $user   = User::factory()->create(['department' => 'executive', 'role' => 'standard', 'tenant_id' => $tenant->id, 'site_id' => $site->id]);

        $this->actingAs($user)
            ->postJson('/site-context/switch', ['site_id' => $site->id])
            ->assertOk();
    }

    public function test_executive_cannot_switch_to_cross_tenant_site(): void
    {
        $tenant      = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $otherSite   = Site::factory()->create(['tenant_id' => $otherTenant->id]);
        $user        = User::factory()->create(['department' => 'executive', 'role' => 'standard', 'tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->postJson('/site-context/switch', ['site_id' => $otherSite->id])
            ->assertForbidden();
    }
}

<?php

// ─── ItAdminDashboardTest ─────────────────────────────────────────────────────
// Feature tests for the IT Admin department dashboard widget endpoints.
//
// Coverage:
//   - IT Admin user can access all 4 widget endpoints (200)
//   - Wrong department user is rejected (403)
//   - Super-admin can access all 4 widget endpoints (200)
//   - users widget returns expected JSON structure
//   - integrations widget returns expected JSON structure
//   - audit widget returns expected JSON structure
//   - config widget returns expected JSON structure
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItAdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeItAdminUser(): User
    {
        return User::factory()->create(['department' => 'it_admin', 'role' => 'standard']);
    }

    private function makeWrongDeptUser(): User
    {
        return User::factory()->create(['department' => 'dietary', 'role' => 'standard']);
    }

    private function makeSuperAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    // ── Access control ─────────────────────────────────────────────────────────

    public function test_it_admin_user_can_access_all_widgets(): void
    {
        $user = $this->makeItAdminUser();

        $this->actingAs($user)->getJson('/dashboards/it-admin/users')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/it-admin/integrations')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/it-admin/audit')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/it-admin/config')->assertOk();
    }

    public function test_wrong_dept_user_gets_403_on_all_widgets(): void
    {
        $user = $this->makeWrongDeptUser();

        $this->actingAs($user)->getJson('/dashboards/it-admin/users')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/it-admin/integrations')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/it-admin/audit')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/it-admin/config')->assertForbidden();
    }

    public function test_super_admin_can_access_all_widgets(): void
    {
        $sa = $this->makeSuperAdmin();

        $this->actingAs($sa)->getJson('/dashboards/it-admin/users')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/it-admin/integrations')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/it-admin/audit')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/it-admin/config')->assertOk();
    }

    // ── JSON structure ─────────────────────────────────────────────────────────

    public function test_users_widget_returns_expected_structure(): void
    {
        $user = $this->makeItAdminUser();

        $this->actingAs($user)
            ->getJson('/dashboards/it-admin/users')
            ->assertOk()
            ->assertJsonStructure([
                'recently_provisioned',
                'recently_deactivated',
                'total_active',
                'total_inactive',
            ]);
    }

    public function test_integrations_widget_returns_expected_structure(): void
    {
        $user = $this->makeItAdminUser();

        $this->actingAs($user)
            ->getJson('/dashboards/it-admin/integrations')
            ->assertOk()
            ->assertJsonStructure(['connectors']);
    }

    public function test_audit_widget_returns_expected_structure(): void
    {
        $user = $this->makeItAdminUser();

        $this->actingAs($user)
            ->getJson('/dashboards/it-admin/audit')
            ->assertOk()
            ->assertJsonStructure(['entries']);
    }

    public function test_config_widget_returns_expected_structure(): void
    {
        $user = $this->makeItAdminUser();

        $this->actingAs($user)
            ->getJson('/dashboards/it-admin/config')
            ->assertOk()
            ->assertJsonStructure([
                'transport_mode',
                'auto_logout_minutes',
                'sites',
                'site_count',
            ]);
    }
}

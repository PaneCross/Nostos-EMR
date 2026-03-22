<?php

// ─── TransportationDashboardTest ──────────────────────────────────────────────
// Feature tests for the Transportation department dashboard widget endpoints.
//
// Coverage:
//   - Transportation user can access all 4 widget endpoints (200)
//   - Wrong department user is rejected (403)
//   - Super-admin can access all 4 widget endpoints (200)
//   - manifest-summary widget returns expected JSON structure
//   - add-ons widget returns expected JSON structure
//   - flag-alerts widget returns expected JSON structure
//   - config widget returns expected JSON structure
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransportationDashboardTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeTransportUser(): User
    {
        return User::factory()->create(['department' => 'transportation', 'role' => 'standard']);
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

    public function test_transportation_user_can_access_all_widgets(): void
    {
        $user = $this->makeTransportUser();

        $this->actingAs($user)->getJson('/dashboards/transportation/manifest-summary')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/transportation/add-ons')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/transportation/flag-alerts')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/transportation/config')->assertOk();
    }

    public function test_wrong_dept_user_gets_403_on_all_widgets(): void
    {
        $user = $this->makeWrongDeptUser();

        $this->actingAs($user)->getJson('/dashboards/transportation/manifest-summary')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/transportation/add-ons')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/transportation/flag-alerts')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/transportation/config')->assertForbidden();
    }

    public function test_super_admin_can_access_all_widgets(): void
    {
        $sa = $this->makeSuperAdmin();

        $this->actingAs($sa)->getJson('/dashboards/transportation/manifest-summary')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/transportation/add-ons')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/transportation/flag-alerts')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/transportation/config')->assertOk();
    }

    // ── JSON structure ─────────────────────────────────────────────────────────

    public function test_manifest_summary_widget_returns_expected_structure(): void
    {
        $user = $this->makeTransportUser();

        $this->actingAs($user)
            ->getJson('/dashboards/transportation/manifest-summary')
            ->assertOk()
            ->assertJsonStructure(['summary', 'total', 'cancelled_count']);
    }

    public function test_add_ons_widget_returns_expected_structure(): void
    {
        $user = $this->makeTransportUser();

        $this->actingAs($user)
            ->getJson('/dashboards/transportation/add-ons')
            ->assertOk()
            ->assertJsonStructure(['add_ons', 'count']);
    }

    public function test_flag_alerts_widget_returns_expected_structure(): void
    {
        $user = $this->makeTransportUser();

        $this->actingAs($user)
            ->getJson('/dashboards/transportation/flag-alerts')
            ->assertOk()
            ->assertJsonStructure(['flags', 'count']);
    }

    public function test_config_widget_returns_expected_structure(): void
    {
        $user = $this->makeTransportUser();

        $this->actingAs($user)
            ->getJson('/dashboards/transportation/config')
            ->assertOk()
            ->assertJsonStructure(['transport_mode', 'is_broker_mode', 'auto_logout_minutes']);
    }
}

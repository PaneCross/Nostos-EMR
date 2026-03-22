<?php

// ─── PharmacyDashboardTest ────────────────────────────────────────────────────
// Feature tests for the Pharmacy department dashboard widget endpoints.
//
// Coverage:
//   - Pharmacy user can access all 4 widget endpoints (200)
//   - Wrong department user is rejected (403)
//   - Super-admin can access all 4 widget endpoints (200)
//   - med-changes widget returns expected JSON structure
//   - interactions widget returns expected JSON structure
//   - controlled widget returns expected JSON structure
//   - refills widget returns expected JSON structure
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PharmacyDashboardTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makePharmacyUser(): User
    {
        return User::factory()->create(['department' => 'pharmacy', 'role' => 'standard']);
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

    public function test_pharmacy_user_can_access_all_widgets(): void
    {
        $user = $this->makePharmacyUser();

        $this->actingAs($user)->getJson('/dashboards/pharmacy/med-changes')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/pharmacy/interactions')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/pharmacy/controlled')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/pharmacy/refills')->assertOk();
    }

    public function test_wrong_dept_user_gets_403_on_all_widgets(): void
    {
        $user = $this->makeWrongDeptUser();

        $this->actingAs($user)->getJson('/dashboards/pharmacy/med-changes')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/pharmacy/interactions')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/pharmacy/controlled')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/pharmacy/refills')->assertForbidden();
    }

    public function test_super_admin_can_access_all_widgets(): void
    {
        $sa = $this->makeSuperAdmin();

        $this->actingAs($sa)->getJson('/dashboards/pharmacy/med-changes')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/pharmacy/interactions')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/pharmacy/controlled')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/pharmacy/refills')->assertOk();
    }

    // ── JSON structure ─────────────────────────────────────────────────────────

    public function test_med_changes_widget_returns_expected_structure(): void
    {
        $user = $this->makePharmacyUser();

        $this->actingAs($user)
            ->getJson('/dashboards/pharmacy/med-changes')
            ->assertOk()
            ->assertJsonStructure([
                'new_orders',
                'new_orders_count',
                'discontinued',
                'discontinued_count',
            ]);
    }

    public function test_interactions_widget_returns_expected_structure(): void
    {
        $user = $this->makePharmacyUser();

        $this->actingAs($user)
            ->getJson('/dashboards/pharmacy/interactions')
            ->assertOk()
            ->assertJsonStructure(['alerts', 'total_count']);
    }

    public function test_controlled_widget_returns_expected_structure(): void
    {
        $user = $this->makePharmacyUser();

        $this->actingAs($user)
            ->getJson('/dashboards/pharmacy/controlled')
            ->assertOk()
            ->assertJsonStructure(['records', 'count']);
    }

    public function test_refills_widget_returns_expected_structure(): void
    {
        $user = $this->makePharmacyUser();

        $this->actingAs($user)
            ->getJson('/dashboards/pharmacy/refills')
            ->assertOk()
            ->assertJsonStructure(['medications', 'count']);
    }
}

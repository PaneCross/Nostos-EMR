<?php

// ─── HomeCareDashboardTest ────────────────────────────────────────────────────
// Feature tests for the Home Care department dashboard widget endpoints.
//
// Coverage:
//   - Home care user can access all 4 widget endpoints (200)
//   - Wrong department user is rejected (403)
//   - Super-admin can access all 4 widget endpoints (200)
//   - Schedule, adl-alerts, goals, sdrs widgets return expected JSON structure
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeCareDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function makeHomeCareUser(): User
    {
        return User::factory()->create(['department' => 'home_care', 'role' => 'standard']);
    }

    private function makeWrongDeptUser(): User
    {
        return User::factory()->create(['department' => 'qa_compliance', 'role' => 'standard']);
    }

    private function makeSuperAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    // ── Access control ─────────────────────────────────────────────────────────

    public function test_home_care_user_can_access_all_widgets(): void
    {
        $user = $this->makeHomeCareUser();

        $this->actingAs($user)->getJson('/dashboards/home-care/schedule')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/home-care/adl-alerts')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/home-care/goals')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/home-care/sdrs')->assertOk();
    }

    public function test_wrong_dept_user_gets_403_on_all_widgets(): void
    {
        $user = $this->makeWrongDeptUser();

        $this->actingAs($user)->getJson('/dashboards/home-care/schedule')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/home-care/adl-alerts')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/home-care/goals')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/home-care/sdrs')->assertForbidden();
    }

    public function test_super_admin_can_access_all_widgets(): void
    {
        $sa = $this->makeSuperAdmin();

        $this->actingAs($sa)->getJson('/dashboards/home-care/schedule')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/home-care/adl-alerts')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/home-care/goals')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/home-care/sdrs')->assertOk();
    }

    // ── JSON structure ─────────────────────────────────────────────────────────

    public function test_schedule_widget_returns_appointments_array(): void
    {
        $user = $this->makeHomeCareUser();

        $this->actingAs($user)
            ->getJson('/dashboards/home-care/schedule')
            ->assertOk()
            ->assertJsonStructure(['appointments']);
    }

    public function test_adl_alerts_widget_returns_alerts_with_count(): void
    {
        $user = $this->makeHomeCareUser();

        $this->actingAs($user)
            ->getJson('/dashboards/home-care/adl-alerts')
            ->assertOk()
            ->assertJsonStructure(['alerts', 'unacknowledged_count']);
    }

    public function test_goals_widget_returns_goals_array(): void
    {
        $user = $this->makeHomeCareUser();

        $this->actingAs($user)
            ->getJson('/dashboards/home-care/goals')
            ->assertOk()
            ->assertJsonStructure(['goals']);
    }

    public function test_sdrs_widget_returns_sdrs_with_counts(): void
    {
        $user = $this->makeHomeCareUser();

        $this->actingAs($user)
            ->getJson('/dashboards/home-care/sdrs')
            ->assertOk()
            ->assertJsonStructure(['sdrs', 'overdue_count', 'open_count']);
    }
}

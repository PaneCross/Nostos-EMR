<?php

// ─── BehavioralHealthDashboardTest ────────────────────────────────────────────
// Feature tests for the Behavioral Health department dashboard widget endpoints.
//
// Coverage:
//   - Behavioral health user can access all 4 widget endpoints (200)
//   - Wrong department user is rejected (403)
//   - Super-admin can access all 4 widget endpoints (200)
//   - Schedule, assessments, sdrs, goals widgets return expected JSON structure
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BehavioralHealthDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function makeBhUser(): User
    {
        return User::factory()->create(['department' => 'behavioral_health', 'role' => 'standard']);
    }

    private function makeWrongDeptUser(): User
    {
        return User::factory()->create(['department' => 'transportation', 'role' => 'standard']);
    }

    private function makeSuperAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    // ── Access control ─────────────────────────────────────────────────────────

    public function test_behavioral_health_user_can_access_all_widgets(): void
    {
        $user = $this->makeBhUser();

        $this->actingAs($user)->getJson('/dashboards/behavioral-health/schedule')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/behavioral-health/assessments')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/behavioral-health/sdrs')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/behavioral-health/goals')->assertOk();
    }

    public function test_wrong_dept_user_gets_403_on_all_widgets(): void
    {
        $user = $this->makeWrongDeptUser();

        $this->actingAs($user)->getJson('/dashboards/behavioral-health/schedule')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/behavioral-health/assessments')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/behavioral-health/sdrs')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/behavioral-health/goals')->assertForbidden();
    }

    public function test_super_admin_can_access_all_widgets(): void
    {
        $sa = $this->makeSuperAdmin();

        $this->actingAs($sa)->getJson('/dashboards/behavioral-health/schedule')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/behavioral-health/assessments')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/behavioral-health/sdrs')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/behavioral-health/goals')->assertOk();
    }

    // ── JSON structure ─────────────────────────────────────────────────────────

    public function test_schedule_widget_returns_appointments_array(): void
    {
        $user = $this->makeBhUser();

        $this->actingAs($user)
            ->getJson('/dashboards/behavioral-health/schedule')
            ->assertOk()
            ->assertJsonStructure(['appointments']);
    }

    public function test_assessments_widget_returns_overdue_and_due_soon(): void
    {
        $user = $this->makeBhUser();

        $this->actingAs($user)
            ->getJson('/dashboards/behavioral-health/assessments')
            ->assertOk()
            ->assertJsonStructure([
                'overdue',
                'due_soon',
                'overdue_count',
                'due_soon_count',
            ]);
    }

    public function test_sdrs_widget_returns_sdrs_with_counts(): void
    {
        $user = $this->makeBhUser();

        $this->actingAs($user)
            ->getJson('/dashboards/behavioral-health/sdrs')
            ->assertOk()
            ->assertJsonStructure(['sdrs', 'overdue_count', 'open_count']);
    }

    public function test_goals_widget_returns_goals_array(): void
    {
        $user = $this->makeBhUser();

        $this->actingAs($user)
            ->getJson('/dashboards/behavioral-health/goals')
            ->assertOk()
            ->assertJsonStructure(['goals']);
    }
}

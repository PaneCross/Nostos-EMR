<?php

// ─── ActivitiesDashboardTest ──────────────────────────────────────────────────
// Feature tests for the Activities / Recreation Therapy dashboard widget endpoints.
//
// Coverage:
//   - Activities user can access all 4 widget endpoints (200)
//   - Wrong department user is rejected (403)
//   - Super-admin can access all 4 widget endpoints (200)
//   - Schedule, goals, sdrs, docs widgets return expected JSON structure
//   - Schedule widget returns day_center_count field
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivitiesDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function makeActivitiesUser(): User
    {
        return User::factory()->create(['department' => 'activities', 'role' => 'standard']);
    }

    private function makeWrongDeptUser(): User
    {
        return User::factory()->create(['department' => 'idt', 'role' => 'standard']);
    }

    private function makeSuperAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    // ── Access control ─────────────────────────────────────────────────────────

    public function test_activities_user_can_access_all_widgets(): void
    {
        $user = $this->makeActivitiesUser();

        $this->actingAs($user)->getJson('/dashboards/activities/schedule')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/activities/goals')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/activities/sdrs')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/activities/docs')->assertOk();
    }

    public function test_wrong_dept_user_gets_403_on_all_widgets(): void
    {
        $user = $this->makeWrongDeptUser();

        $this->actingAs($user)->getJson('/dashboards/activities/schedule')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/activities/goals')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/activities/sdrs')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/activities/docs')->assertForbidden();
    }

    public function test_super_admin_can_access_all_widgets(): void
    {
        $sa = $this->makeSuperAdmin();

        $this->actingAs($sa)->getJson('/dashboards/activities/schedule')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/activities/goals')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/activities/sdrs')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/activities/docs')->assertOk();
    }

    // ── JSON structure ─────────────────────────────────────────────────────────

    public function test_schedule_widget_returns_appointments_and_day_center_count(): void
    {
        $user = $this->makeActivitiesUser();

        $this->actingAs($user)
            ->getJson('/dashboards/activities/schedule')
            ->assertOk()
            ->assertJsonStructure(['appointments', 'day_center_count']);
    }

    public function test_goals_widget_returns_goals_array(): void
    {
        $user = $this->makeActivitiesUser();

        $this->actingAs($user)
            ->getJson('/dashboards/activities/goals')
            ->assertOk()
            ->assertJsonStructure(['goals']);
    }

    public function test_sdrs_widget_returns_sdrs_with_counts(): void
    {
        $user = $this->makeActivitiesUser();

        $this->actingAs($user)
            ->getJson('/dashboards/activities/sdrs')
            ->assertOk()
            ->assertJsonStructure(['sdrs', 'overdue_count', 'open_count']);
    }

    public function test_docs_widget_returns_notes_with_count(): void
    {
        $user = $this->makeActivitiesUser();

        $this->actingAs($user)
            ->getJson('/dashboards/activities/docs')
            ->assertOk()
            ->assertJsonStructure(['notes', 'unsigned_count']);
    }
}

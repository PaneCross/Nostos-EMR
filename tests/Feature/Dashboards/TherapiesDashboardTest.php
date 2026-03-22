<?php

// ─── TherapiesDashboardTest ───────────────────────────────────────────────────
// Feature tests for the Therapies department dashboard widget endpoints.
//
// Coverage:
//   - Therapies user can access all 4 widget endpoints (200)
//   - Wrong department user is rejected (403)
//   - Super-admin can access all 4 widget endpoints (200)
//   - Schedule, goals, sdrs, docs widgets return expected JSON structure
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TherapiesDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function makeTherapiesUser(): User
    {
        return User::factory()->create(['department' => 'therapies', 'role' => 'standard']);
    }

    private function makeWrongDeptUser(): User
    {
        return User::factory()->create(['department' => 'finance', 'role' => 'standard']);
    }

    private function makeSuperAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    // ── Access control ─────────────────────────────────────────────────────────

    public function test_therapies_user_can_access_all_widgets(): void
    {
        $user = $this->makeTherapiesUser();

        $this->actingAs($user)->getJson('/dashboards/therapies/schedule')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/therapies/goals')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/therapies/sdrs')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/therapies/docs')->assertOk();
    }

    public function test_wrong_dept_user_gets_403_on_all_widgets(): void
    {
        $user = $this->makeWrongDeptUser();

        $this->actingAs($user)->getJson('/dashboards/therapies/schedule')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/therapies/goals')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/therapies/sdrs')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/therapies/docs')->assertForbidden();
    }

    public function test_super_admin_can_access_all_widgets(): void
    {
        $sa = $this->makeSuperAdmin();

        $this->actingAs($sa)->getJson('/dashboards/therapies/schedule')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/therapies/goals')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/therapies/sdrs')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/therapies/docs')->assertOk();
    }

    // ── JSON structure ─────────────────────────────────────────────────────────

    public function test_schedule_widget_returns_appointments_array(): void
    {
        $user = $this->makeTherapiesUser();

        $this->actingAs($user)
            ->getJson('/dashboards/therapies/schedule')
            ->assertOk()
            ->assertJsonStructure(['appointments']);
    }

    public function test_goals_widget_returns_goals_array(): void
    {
        $user = $this->makeTherapiesUser();

        $this->actingAs($user)
            ->getJson('/dashboards/therapies/goals')
            ->assertOk()
            ->assertJsonStructure(['goals']);
    }

    public function test_sdrs_widget_returns_sdrs_with_counts(): void
    {
        $user = $this->makeTherapiesUser();

        $this->actingAs($user)
            ->getJson('/dashboards/therapies/sdrs')
            ->assertOk()
            ->assertJsonStructure(['sdrs', 'overdue_count', 'open_count']);
    }

    public function test_docs_widget_returns_notes_with_count(): void
    {
        $user = $this->makeTherapiesUser();

        $this->actingAs($user)
            ->getJson('/dashboards/therapies/docs')
            ->assertOk()
            ->assertJsonStructure(['notes', 'unsigned_count']);
    }
}

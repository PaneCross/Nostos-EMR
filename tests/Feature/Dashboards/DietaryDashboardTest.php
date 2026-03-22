<?php

// ─── DietaryDashboardTest ─────────────────────────────────────────────────────
// Feature tests for the Dietary / Nutrition department dashboard widget endpoints.
//
// Coverage:
//   - Dietary user can access all 4 widget endpoints (200)
//   - Wrong department user is rejected (403)
//   - Super-admin can access all 4 widget endpoints (200)
//   - Assessments, goals, restrictions, sdrs widgets return expected JSON structure
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DietaryDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function makeDietaryUser(): User
    {
        return User::factory()->create(['department' => 'dietary', 'role' => 'standard']);
    }

    private function makeWrongDeptUser(): User
    {
        return User::factory()->create(['department' => 'enrollment', 'role' => 'standard']);
    }

    private function makeSuperAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    // ── Access control ─────────────────────────────────────────────────────────

    public function test_dietary_user_can_access_all_widgets(): void
    {
        $user = $this->makeDietaryUser();

        $this->actingAs($user)->getJson('/dashboards/dietary/assessments')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/dietary/goals')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/dietary/restrictions')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/dietary/sdrs')->assertOk();
    }

    public function test_wrong_dept_user_gets_403_on_all_widgets(): void
    {
        $user = $this->makeWrongDeptUser();

        $this->actingAs($user)->getJson('/dashboards/dietary/assessments')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/dietary/goals')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/dietary/restrictions')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/dietary/sdrs')->assertForbidden();
    }

    public function test_super_admin_can_access_all_widgets(): void
    {
        $sa = $this->makeSuperAdmin();

        $this->actingAs($sa)->getJson('/dashboards/dietary/assessments')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/dietary/goals')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/dietary/restrictions')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/dietary/sdrs')->assertOk();
    }

    // ── JSON structure ─────────────────────────────────────────────────────────

    public function test_assessments_widget_returns_overdue_and_due_soon(): void
    {
        $user = $this->makeDietaryUser();

        $this->actingAs($user)
            ->getJson('/dashboards/dietary/assessments')
            ->assertOk()
            ->assertJsonStructure([
                'overdue',
                'due_soon',
                'overdue_count',
                'due_soon_count',
            ]);
    }

    public function test_goals_widget_returns_goals_array(): void
    {
        $user = $this->makeDietaryUser();

        $this->actingAs($user)
            ->getJson('/dashboards/dietary/goals')
            ->assertOk()
            ->assertJsonStructure(['goals']);
    }

    public function test_restrictions_widget_returns_counts_and_critical_allergies(): void
    {
        $user = $this->makeDietaryUser();

        $this->actingAs($user)
            ->getJson('/dashboards/dietary/restrictions')
            ->assertOk()
            ->assertJsonStructure([
                'counts_by_type',
                'critical_food_allergies',
            ]);
    }

    public function test_sdrs_widget_returns_sdrs_with_counts(): void
    {
        $user = $this->makeDietaryUser();

        $this->actingAs($user)
            ->getJson('/dashboards/dietary/sdrs')
            ->assertOk()
            ->assertJsonStructure(['sdrs', 'overdue_count', 'open_count']);
    }
}

<?php

// ─── EnrollmentDashboardTest ──────────────────────────────────────────────────
// Feature tests for the Enrollment department dashboard widget endpoints.
//
// Coverage:
//   - Enrollment user can access all 4 widget endpoints (200)
//   - Wrong department user is rejected (403)
//   - Super-admin can access all 4 widget endpoints (200)
//   - pipeline widget returns expected JSON structure
//   - eligibility-pending widget returns expected JSON structure
//   - disenrollments widget returns expected JSON structure
//   - new-referrals widget returns expected JSON structure
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentDashboardTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeEnrollmentUser(): User
    {
        return User::factory()->create(['department' => 'enrollment', 'role' => 'standard']);
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

    public function test_enrollment_user_can_access_all_widgets(): void
    {
        $user = $this->makeEnrollmentUser();

        $this->actingAs($user)->getJson('/dashboards/enrollment/pipeline')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/enrollment/eligibility-pending')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/enrollment/disenrollments')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/enrollment/new-referrals')->assertOk();
    }

    public function test_wrong_dept_user_gets_403_on_all_widgets(): void
    {
        $user = $this->makeWrongDeptUser();

        $this->actingAs($user)->getJson('/dashboards/enrollment/pipeline')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/enrollment/eligibility-pending')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/enrollment/disenrollments')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/enrollment/new-referrals')->assertForbidden();
    }

    public function test_super_admin_can_access_all_widgets(): void
    {
        $sa = $this->makeSuperAdmin();

        $this->actingAs($sa)->getJson('/dashboards/enrollment/pipeline')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/enrollment/eligibility-pending')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/enrollment/disenrollments')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/enrollment/new-referrals')->assertOk();
    }

    // ── JSON structure ─────────────────────────────────────────────────────────

    public function test_pipeline_widget_returns_expected_structure(): void
    {
        $user = $this->makeEnrollmentUser();

        $this->actingAs($user)
            ->getJson('/dashboards/enrollment/pipeline')
            ->assertOk()
            ->assertJsonStructure(['pipeline', 'total_active']);
    }

    public function test_eligibility_pending_widget_returns_expected_structure(): void
    {
        $user = $this->makeEnrollmentUser();

        $this->actingAs($user)
            ->getJson('/dashboards/enrollment/eligibility-pending')
            ->assertOk()
            ->assertJsonStructure(['referrals', 'count']);
    }

    public function test_disenrollments_widget_returns_expected_structure(): void
    {
        $user = $this->makeEnrollmentUser();

        $this->actingAs($user)
            ->getJson('/dashboards/enrollment/disenrollments')
            ->assertOk()
            ->assertJsonStructure(['participants', 'count']);
    }

    public function test_new_referrals_widget_returns_expected_structure(): void
    {
        $user = $this->makeEnrollmentUser();

        $this->actingAs($user)
            ->getJson('/dashboards/enrollment/new-referrals')
            ->assertOk()
            ->assertJsonStructure(['referrals', 'week_count', 'week_start']);
    }
}

<?php

// ─── QaComplianceDashboardTest ────────────────────────────────────────────────
// Feature tests for the QA/Compliance department dashboard widget endpoints.
//
// Coverage:
//   - QA compliance user can access all 4 widget endpoints (200)
//   - Wrong department user is rejected (403)
//   - Super-admin can access all 4 widget endpoints (200)
//   - metrics widget returns expected JSON structure (6 KPI keys)
//   - incidents widget returns expected JSON structure
//   - docs widget returns expected JSON structure
//   - care-plans widget returns expected JSON structure
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QaComplianceDashboardTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeQaUser(): User
    {
        return User::factory()->create(['department' => 'qa_compliance', 'role' => 'standard']);
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

    public function test_qa_compliance_user_can_access_all_widgets(): void
    {
        $user = $this->makeQaUser();

        $this->actingAs($user)->getJson('/dashboards/qa-compliance/metrics')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/qa-compliance/incidents')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/qa-compliance/docs')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/qa-compliance/care-plans')->assertOk();
    }

    public function test_wrong_dept_user_gets_403_on_all_widgets(): void
    {
        $user = $this->makeWrongDeptUser();

        $this->actingAs($user)->getJson('/dashboards/qa-compliance/metrics')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/qa-compliance/incidents')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/qa-compliance/docs')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/qa-compliance/care-plans')->assertForbidden();
    }

    public function test_super_admin_can_access_all_widgets(): void
    {
        $sa = $this->makeSuperAdmin();

        $this->actingAs($sa)->getJson('/dashboards/qa-compliance/metrics')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/qa-compliance/incidents')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/qa-compliance/docs')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/qa-compliance/care-plans')->assertOk();
    }

    // ── JSON structure ─────────────────────────────────────────────────────────

    public function test_metrics_widget_returns_all_six_kpi_keys(): void
    {
        $user = $this->makeQaUser();

        $this->actingAs($user)
            ->getJson('/dashboards/qa-compliance/metrics')
            ->assertOk()
            ->assertJsonStructure([
                'sdr_compliance_rate',
                'overdue_assessments_count',
                'unsigned_notes_count',
                'open_incidents_count',
                'overdue_care_plans_count',
                'hospitalizations_count',
            ]);
    }

    public function test_incidents_widget_returns_expected_structure(): void
    {
        $user = $this->makeQaUser();

        $this->actingAs($user)
            ->getJson('/dashboards/qa-compliance/incidents')
            ->assertOk()
            ->assertJsonStructure(['incidents', 'open_count', 'rca_pending_count']);
    }

    public function test_docs_widget_returns_expected_structure(): void
    {
        $user = $this->makeQaUser();

        $this->actingAs($user)
            ->getJson('/dashboards/qa-compliance/docs')
            ->assertOk()
            ->assertJsonStructure([
                'unsigned_notes',
                'unsigned_count',
                'notes_by_department',
                'overdue_assessments',
                'overdue_assess_count',
            ]);
    }

    public function test_care_plans_widget_returns_expected_structure(): void
    {
        $user = $this->makeQaUser();

        $this->actingAs($user)
            ->getJson('/dashboards/qa-compliance/care-plans')
            ->assertOk()
            ->assertJsonStructure(['care_plans', 'overdue_count']);
    }
}

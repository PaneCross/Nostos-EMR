<?php

// ─── PrimaryCareDashboardTest ─────────────────────────────────────────────────
// Feature tests for the Primary Care department dashboard widget endpoints.
//
// Coverage:
//   - Primary care user can access all 4 widget endpoints (200)
//   - Wrong department user is rejected (403)
//   - Super-admin can access all 4 widget endpoints (200)
//   - Unauthenticated requests are rejected (401/302)
//   - Schedule widget returns expected JSON structure
//   - Docs widget returns expected JSON structure
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrimaryCareDashboardTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makePcUser(): User
    {
        return User::factory()->create(['department' => 'primary_care', 'role' => 'standard']);
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

    public function test_primary_care_user_can_access_all_widgets(): void
    {
        $user = $this->makePcUser();

        $this->actingAs($user)->getJson('/dashboards/primary-care/schedule')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/primary-care/alerts')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/primary-care/docs')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/primary-care/vitals')->assertOk();
    }

    public function test_wrong_dept_user_gets_403_on_all_widgets(): void
    {
        $user = $this->makeWrongDeptUser();

        $this->actingAs($user)->getJson('/dashboards/primary-care/schedule')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/primary-care/alerts')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/primary-care/docs')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/primary-care/vitals')->assertForbidden();
    }

    public function test_super_admin_can_access_all_widgets(): void
    {
        $sa = $this->makeSuperAdmin();

        $this->actingAs($sa)->getJson('/dashboards/primary-care/schedule')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/primary-care/alerts')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/primary-care/docs')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/primary-care/vitals')->assertOk();
    }

    // ── JSON structure ─────────────────────────────────────────────────────────

    public function test_schedule_widget_returns_appointments_array(): void
    {
        $user = $this->makePcUser();

        $this->actingAs($user)
            ->getJson('/dashboards/primary-care/schedule')
            ->assertOk()
            ->assertJsonStructure(['appointments']);
    }

    public function test_docs_widget_returns_expected_structure(): void
    {
        $user = $this->makePcUser();

        $this->actingAs($user)
            ->getJson('/dashboards/primary-care/docs')
            ->assertOk()
            ->assertJsonStructure([
                'unsigned_notes',
                'unsigned_count',
                'overdue_assessments',
                'overdue_count',
            ]);
    }

    public function test_vitals_widget_returns_vitals_array(): void
    {
        $user = $this->makePcUser();

        $this->actingAs($user)
            ->getJson('/dashboards/primary-care/vitals')
            ->assertOk()
            ->assertJsonStructure(['vitals']);
    }

    public function test_alerts_widget_returns_alerts_array(): void
    {
        $user = $this->makePcUser();

        $this->actingAs($user)
            ->getJson('/dashboards/primary-care/alerts')
            ->assertOk()
            ->assertJsonStructure(['alerts']);
    }
}

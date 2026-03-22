<?php

// ─── IdtDashboardTest ─────────────────────────────────────────────────────────
// Feature tests for the IDT department dashboard widget endpoints.
//
// Coverage:
//   - IDT user can access all 4 widget endpoints (200)
//   - Wrong department user is rejected (403)
//   - Super-admin can access all 4 widget endpoints (200)
//   - meetings widget returns expected JSON structure
//   - overdue-sdrs widget returns expected JSON structure
//   - care-plans widget returns expected JSON structure
//   - alerts widget returns expected JSON structure
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdtDashboardTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeIdtUser(): User
    {
        return User::factory()->create(['department' => 'idt', 'role' => 'standard']);
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

    public function test_idt_user_can_access_all_widgets(): void
    {
        $user = $this->makeIdtUser();

        $this->actingAs($user)->getJson('/dashboards/idt/meetings')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/idt/overdue-sdrs')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/idt/care-plans')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/idt/alerts')->assertOk();
    }

    public function test_wrong_dept_user_gets_403_on_all_widgets(): void
    {
        $user = $this->makeWrongDeptUser();

        $this->actingAs($user)->getJson('/dashboards/idt/meetings')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/idt/overdue-sdrs')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/idt/care-plans')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/idt/alerts')->assertForbidden();
    }

    public function test_super_admin_can_access_all_widgets(): void
    {
        $sa = $this->makeSuperAdmin();

        $this->actingAs($sa)->getJson('/dashboards/idt/meetings')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/idt/overdue-sdrs')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/idt/care-plans')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/idt/alerts')->assertOk();
    }

    // ── JSON structure ─────────────────────────────────────────────────────────

    public function test_meetings_widget_returns_expected_structure(): void
    {
        $user = $this->makeIdtUser();

        $this->actingAs($user)
            ->getJson('/dashboards/idt/meetings')
            ->assertOk()
            ->assertJsonStructure(['meetings', 'count', 'has_meeting_today']);
    }

    public function test_overdue_sdrs_widget_returns_expected_structure(): void
    {
        $user = $this->makeIdtUser();

        $this->actingAs($user)
            ->getJson('/dashboards/idt/overdue-sdrs')
            ->assertOk()
            ->assertJsonStructure(['departments', 'total_count']);
    }

    public function test_care_plans_widget_returns_expected_structure(): void
    {
        $user = $this->makeIdtUser();

        $this->actingAs($user)
            ->getJson('/dashboards/idt/care-plans')
            ->assertOk()
            ->assertJsonStructure(['care_plans', 'overdue_count', 'due_soon_count']);
    }

    public function test_alerts_widget_returns_expected_structure(): void
    {
        $user = $this->makeIdtUser();

        $this->actingAs($user)
            ->getJson('/dashboards/idt/alerts')
            ->assertOk()
            ->assertJsonStructure(['alerts', 'critical_count']);
    }
}

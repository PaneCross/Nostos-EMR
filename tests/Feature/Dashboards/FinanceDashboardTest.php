<?php

// ─── FinanceDashboardTest ─────────────────────────────────────────────────────
// Feature tests for the Finance department dashboard widget endpoints.
// Note: Tests FinanceWidgetController (at /dashboards/finance/*), which is
// distinct from FinanceDashboardController (which serves the full /finance/dashboard
// Inertia page).
//
// Coverage:
//   - Finance user can access all 4 widget endpoints (200)
//   - Wrong department user is rejected (403)
//   - Super-admin can access all 4 widget endpoints (200)
//   - capitation widget returns expected JSON structure
//   - authorizations widget returns expected JSON structure
//   - enrollment-changes widget returns expected JSON structure
//   - encounters widget returns expected JSON structure
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceDashboardTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeFinanceUser(): User
    {
        return User::factory()->create(['department' => 'finance', 'role' => 'standard']);
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

    public function test_finance_user_can_access_all_widgets(): void
    {
        $user = $this->makeFinanceUser();

        $this->actingAs($user)->getJson('/dashboards/finance/capitation')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/finance/authorizations')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/finance/enrollment-changes')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/finance/encounters')->assertOk();
    }

    public function test_wrong_dept_user_gets_403_on_all_widgets(): void
    {
        $user = $this->makeWrongDeptUser();

        $this->actingAs($user)->getJson('/dashboards/finance/capitation')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/finance/authorizations')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/finance/enrollment-changes')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/finance/encounters')->assertForbidden();
    }

    public function test_super_admin_can_access_all_widgets(): void
    {
        $sa = $this->makeSuperAdmin();

        $this->actingAs($sa)->getJson('/dashboards/finance/capitation')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/finance/authorizations')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/finance/enrollment-changes')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/finance/encounters')->assertOk();
    }

    // ── JSON structure ─────────────────────────────────────────────────────────

    public function test_capitation_widget_returns_expected_structure(): void
    {
        $user = $this->makeFinanceUser();

        $this->actingAs($user)
            ->getJson('/dashboards/finance/capitation')
            ->assertOk()
            ->assertJsonStructure([
                'current_month',
                'current_total',
                'prior_month',
                'prior_total',
                'change_percent',
            ]);
    }

    public function test_authorizations_widget_returns_expected_structure(): void
    {
        $user = $this->makeFinanceUser();

        $this->actingAs($user)
            ->getJson('/dashboards/finance/authorizations')
            ->assertOk()
            ->assertJsonStructure(['authorizations', 'expiring_count', 'expiring_this_week']);
    }

    public function test_enrollment_changes_widget_returns_expected_structure(): void
    {
        $user = $this->makeFinanceUser();

        $this->actingAs($user)
            ->getJson('/dashboards/finance/enrollment-changes')
            ->assertOk()
            ->assertJsonStructure([
                'enrolled_this_month',
                'disenrolled_this_month',
                'total_enrolled',
                'net_change',
            ]);
    }

    public function test_encounters_widget_returns_expected_structure(): void
    {
        $user = $this->makeFinanceUser();

        $this->actingAs($user)
            ->getJson('/dashboards/finance/encounters')
            ->assertOk()
            ->assertJsonStructure(['total_encounters', 'this_month_encounters', 'by_service_type']);
    }
}

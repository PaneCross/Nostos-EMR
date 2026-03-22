<?php

// ─── SocialWorkDashboardTest ──────────────────────────────────────────────────
// Feature tests for the Social Work department dashboard widget endpoints.
//
// Coverage:
//   - Social work user can access all 4 widget endpoints (200)
//   - Wrong department user is rejected (403)
//   - Super-admin can access all 4 widget endpoints (200)
//   - Schedule, alerts, sdrs, incidents widgets return expected JSON structure
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature\Dashboards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialWorkDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function makeSocialWorkUser(): User
    {
        return User::factory()->create(['department' => 'social_work', 'role' => 'standard']);
    }

    private function makeWrongDeptUser(): User
    {
        return User::factory()->create(['department' => 'pharmacy', 'role' => 'standard']);
    }

    private function makeSuperAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    // ── Access control ─────────────────────────────────────────────────────────

    public function test_social_work_user_can_access_all_widgets(): void
    {
        $user = $this->makeSocialWorkUser();

        $this->actingAs($user)->getJson('/dashboards/social-work/schedule')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/social-work/alerts')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/social-work/sdrs')->assertOk();
        $this->actingAs($user)->getJson('/dashboards/social-work/incidents')->assertOk();
    }

    public function test_wrong_dept_user_gets_403_on_all_widgets(): void
    {
        $user = $this->makeWrongDeptUser();

        $this->actingAs($user)->getJson('/dashboards/social-work/schedule')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/social-work/alerts')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/social-work/sdrs')->assertForbidden();
        $this->actingAs($user)->getJson('/dashboards/social-work/incidents')->assertForbidden();
    }

    public function test_super_admin_can_access_all_widgets(): void
    {
        $sa = $this->makeSuperAdmin();

        $this->actingAs($sa)->getJson('/dashboards/social-work/schedule')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/social-work/alerts')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/social-work/sdrs')->assertOk();
        $this->actingAs($sa)->getJson('/dashboards/social-work/incidents')->assertOk();
    }

    // ── JSON structure ─────────────────────────────────────────────────────────

    public function test_schedule_widget_returns_appointments_array(): void
    {
        $user = $this->makeSocialWorkUser();

        $this->actingAs($user)
            ->getJson('/dashboards/social-work/schedule')
            ->assertOk()
            ->assertJsonStructure(['appointments']);
    }

    public function test_alerts_widget_returns_alerts_array(): void
    {
        $user = $this->makeSocialWorkUser();

        $this->actingAs($user)
            ->getJson('/dashboards/social-work/alerts')
            ->assertOk()
            ->assertJsonStructure(['alerts']);
    }

    public function test_sdrs_widget_returns_sdrs_with_counts(): void
    {
        $user = $this->makeSocialWorkUser();

        $this->actingAs($user)
            ->getJson('/dashboards/social-work/sdrs')
            ->assertOk()
            ->assertJsonStructure(['sdrs', 'overdue_count', 'open_count']);
    }

    public function test_incidents_widget_returns_incidents_with_count(): void
    {
        $user = $this->makeSocialWorkUser();

        $this->actingAs($user)
            ->getJson('/dashboards/social-work/incidents')
            ->assertOk()
            ->assertJsonStructure(['incidents', 'open_count']);
    }
}

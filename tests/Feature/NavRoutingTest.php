<?php

// ─── NavRoutingTest ────────────────────────────────────────────────────────────
// Verifies W3-2 nav menu fixes: broken hrefs now resolve to live pages, and
// the legacy /idt/minutes redirect lands on the meetings list.
//
// Coverage:
//   - /idt/minutes redirects to /idt/meetings (legacy redirect)
//   - /idt/meetings returns 200 for IDT department user
//   - /scheduling/day-center returns 200 for activities user
//   - /scheduling/day-center/roster returns JSON for authenticated user
//   - /reports returns 200 for any authenticated user
//   - /admin/settings returns 200 for IT Admin user
//   - /admin/settings is accessible (read-only) to non-it_admin
//   - /sdrs returns 200 for IDT user (validates /idt/sdr → /sdrs fix)
//   - Unauthenticated requests redirect to login
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavRoutingTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function user(string $dept = 'primary_care'): User
    {
        return User::factory()->create(['department' => $dept, 'is_active' => true]);
    }

    // ── Legacy redirect: /idt/minutes → /idt/meetings ─────────────────────────

    public function test_idt_minutes_redirects_to_idt_meetings(): void
    {
        $u = $this->user('idt');
        $this->actingAs($u)->get('/idt/minutes')->assertRedirect('/idt/meetings');
    }

    // ── IDT Meeting Minutes list (/idt/meetings) ──────────────────────────────

    public function test_idt_meetings_list_returns_ok_for_idt_user(): void
    {
        $u = $this->user('idt');
        $this->actingAs($u)->get('/idt/meetings')->assertOk();
    }

    public function test_idt_meetings_list_returns_ok_for_it_admin(): void
    {
        $u = $this->user('it_admin');
        $this->actingAs($u)->get('/idt/meetings')->assertOk();
    }

    // ── Day Center (/scheduling/day-center) ───────────────────────────────────

    public function test_day_center_index_returns_ok_for_activities_user(): void
    {
        $u = $this->user('activities');
        $this->actingAs($u)->get('/scheduling/day-center')->assertOk();
    }

    public function test_day_center_index_returns_ok_for_it_admin_user(): void
    {
        // it_admin can view all pages; activities manages, it_admin can also manage
        $u = $this->user('it_admin');
        $this->actingAs($u)->get('/scheduling/day-center')->assertOk();
    }

    public function test_day_center_roster_returns_json(): void
    {
        $u = $this->user('activities');
        $this->actingAs($u)
            ->getJson('/scheduling/day-center/roster')
            ->assertOk()
            ->assertJsonStructure(['roster']);
    }

    // ── Reports (/reports) ────────────────────────────────────────────────────

    public function test_reports_index_returns_ok_for_any_authenticated_user(): void
    {
        $u = $this->user('qa_compliance');
        $this->actingAs($u)->get('/reports')->assertOk();
    }

    public function test_reports_data_endpoint_returns_kpis(): void
    {
        $u = $this->user('finance');
        $this->actingAs($u)
            ->getJson('/reports/data')
            ->assertOk()
            ->assertJsonStructure(['kpis' => ['enrolled_participants', 'open_incidents', 'overdue_sdrs', 'meetings_this_month']]);
    }

    // ── System Settings (/admin/settings) ─────────────────────────────────────

    public function test_system_settings_returns_ok_for_it_admin(): void
    {
        $u = $this->user('it_admin');
        $this->actingAs($u)->get('/admin/settings')->assertOk();
    }

    public function test_system_settings_readable_by_non_it_admin(): void
    {
        // Settings page is visible to all authenticated users (canEdit=false for non-it_admin)
        $u = $this->user('primary_care');
        $this->actingAs($u)->get('/admin/settings')->assertOk();
    }

    public function test_system_settings_update_requires_it_admin(): void
    {
        $u = $this->user('primary_care');
        $this->actingAs($u)
            ->putJson('/admin/settings', ['pace_contract' => 'H9999'])
            ->assertForbidden();
    }

    // ── SDR Tracker (/sdrs — validates /idt/sdr nav fix) ─────────────────────

    public function test_sdrs_index_returns_ok_for_idt_user(): void
    {
        $u = $this->user('idt');
        $this->actingAs($u)->get('/sdrs')->assertOk();
    }

    // ── Unauthenticated access ────────────────────────────────────────────────

    public function test_unauthenticated_request_to_day_center_redirects_to_login(): void
    {
        $this->get('/scheduling/day-center')->assertRedirect('/login');
    }

    public function test_unauthenticated_request_to_reports_redirects_to_login(): void
    {
        $this->get('/reports')->assertRedirect('/login');
    }

    public function test_unauthenticated_request_to_settings_redirects_to_login(): void
    {
        $this->get('/admin/settings')->assertRedirect('/login');
    }
}

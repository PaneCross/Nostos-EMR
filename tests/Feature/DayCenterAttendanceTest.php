<?php

// ─── DayCenterAttendanceTest ───────────────────────────────────────────────────
// Feature tests for the W3-2 Day Center attendance module.
//
// Coverage:
//   - Index page renders for authenticated users
//   - Mark present (check-in): creates attendance record
//   - Mark present is idempotent (updateOrCreate)
//   - Mark absent requires absent_reason
//   - Mark absent with valid reason creates record
//   - Mark excused creates record with status=excused
//   - Only activities/it_admin can mark attendance (403 for other depts)
//   - Cross-tenant participant access returns 403
//   - Roster endpoint returns enrolled participants
//   - Summary endpoint returns pivot counts
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\DayCenterAttendance;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DayCenterAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $activitiesUser;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'DCT',
        ]);
        $this->activitiesUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'activities',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_day_center_index_renders_for_authenticated_user(): void
    {
        $this->actingAs($this->activitiesUser)
            ->get('/scheduling/day-center')
            ->assertOk();
    }

    // ── Mark Present (check-in) ───────────────────────────────────────────────

    public function test_check_in_creates_present_attendance_record(): void
    {
        $today = now()->toDateString();

        $this->actingAs($this->activitiesUser)
            ->postJson('/scheduling/day-center/check-in', [
                'participant_id'  => $this->participant->id,
                'site_id'         => $this->site->id,
                'attendance_date' => $today,
                'status'          => 'present',
            ])
            ->assertOk()
            ->assertJsonPath('attendance.status', 'present');

        $this->assertDatabaseHas('emr_day_center_attendance', [
            'participant_id'  => $this->participant->id,
            'site_id'         => $this->site->id,
            'attendance_date' => $today,
            'status'          => 'present',
            'tenant_id'       => $this->tenant->id,
        ]);
    }

    public function test_check_in_is_idempotent_updates_existing_record(): void
    {
        $today = now()->toDateString();

        // First check-in
        $this->actingAs($this->activitiesUser)
            ->postJson('/scheduling/day-center/check-in', [
                'participant_id'  => $this->participant->id,
                'site_id'         => $this->site->id,
                'attendance_date' => $today,
                'status'          => 'present',
            ])
            ->assertOk();

        // Second check-in (same participant + date + site — should update, not create duplicate)
        $this->actingAs($this->activitiesUser)
            ->postJson('/scheduling/day-center/check-in', [
                'participant_id'  => $this->participant->id,
                'site_id'         => $this->site->id,
                'attendance_date' => $today,
                'status'          => 'late',
            ])
            ->assertOk();

        // Still only one record
        $this->assertDatabaseCount('emr_day_center_attendance', 1);
        $this->assertDatabaseHas('emr_day_center_attendance', [
            'participant_id' => $this->participant->id,
            'status'         => 'late',
        ]);
    }

    // ── Mark Absent ───────────────────────────────────────────────────────────

    public function test_mark_absent_requires_absent_reason(): void
    {
        $this->actingAs($this->activitiesUser)
            ->postJson('/scheduling/day-center/absent', [
                'participant_id'  => $this->participant->id,
                'site_id'         => $this->site->id,
                'attendance_date' => now()->toDateString(),
                'status'          => 'absent',
                // absent_reason intentionally omitted
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['absent_reason']);
    }

    public function test_mark_absent_with_reason_creates_record(): void
    {
        $today = now()->toDateString();

        $this->actingAs($this->activitiesUser)
            ->postJson('/scheduling/day-center/absent', [
                'participant_id'  => $this->participant->id,
                'site_id'         => $this->site->id,
                'attendance_date' => $today,
                'status'          => 'absent',
                'absent_reason'   => 'illness',
            ])
            ->assertOk()
            ->assertJsonPath('attendance.status', 'absent');

        $this->assertDatabaseHas('emr_day_center_attendance', [
            'participant_id' => $this->participant->id,
            'status'         => 'absent',
            'absent_reason'  => 'illness',
            'attendance_date'=> $today,
        ]);
    }

    public function test_mark_excused_creates_record_with_excused_status(): void
    {
        $this->actingAs($this->activitiesUser)
            ->postJson('/scheduling/day-center/absent', [
                'participant_id'  => $this->participant->id,
                'site_id'         => $this->site->id,
                'attendance_date' => now()->toDateString(),
                'status'          => 'excused',
                'absent_reason'   => 'appointment',
                'notes'           => 'Specialist visit',
            ])
            ->assertOk()
            ->assertJsonPath('attendance.status', 'excused');
    }

    // ── Authorization ─────────────────────────────────────────────────────────

    public function test_non_activities_user_cannot_mark_present(): void
    {
        $readOnlyUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'primary_care',
            'is_active'  => true,
        ]);

        $this->actingAs($readOnlyUser)
            ->postJson('/scheduling/day-center/check-in', [
                'participant_id'  => $this->participant->id,
                'site_id'         => $this->site->id,
                'attendance_date' => now()->toDateString(),
                'status'          => 'present',
            ])
            ->assertForbidden();
    }

    public function test_it_admin_can_mark_attendance(): void
    {
        $itAdmin = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'it_admin',
            'is_active'  => true,
        ]);

        $this->actingAs($itAdmin)
            ->postJson('/scheduling/day-center/check-in', [
                'participant_id'  => $this->participant->id,
                'site_id'         => $this->site->id,
                'attendance_date' => now()->toDateString(),
                'status'          => 'present',
            ])
            ->assertOk();
    }

    // ── Tenant isolation ──────────────────────────────────────────────────────

    public function test_cross_tenant_participant_check_in_is_rejected(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherSite   = Site::factory()->create(['tenant_id' => $otherTenant->id, 'mrn_prefix' => 'OTH']);
        $foreignPart = Participant::factory()->enrolled()
            ->forTenant($otherTenant->id)
            ->forSite($otherSite->id)
            ->create();

        $this->actingAs($this->activitiesUser)
            ->postJson('/scheduling/day-center/check-in', [
                'participant_id'  => $foreignPart->id,
                'site_id'         => $this->site->id,
                'attendance_date' => now()->toDateString(),
                'status'          => 'present',
            ])
            ->assertForbidden();
    }

    // ── Roster endpoint ───────────────────────────────────────────────────────

    public function test_roster_returns_enrolled_participants_for_site(): void
    {
        $response = $this->actingAs($this->activitiesUser)
            ->getJson('/scheduling/day-center/roster?site_id=' . $this->site->id)
            ->assertOk()
            ->assertJsonStructure(['roster']);

        $roster = $response->json('roster');
        $ids    = array_column($roster, 'id');
        $this->assertContains($this->participant->id, $ids);
    }

    // ── Summary endpoint ──────────────────────────────────────────────────────

    public function test_summary_returns_pivot_by_status(): void
    {
        $today = now()->toDateString();

        // Seed two records
        DayCenterAttendance::factory()->present()->create([
            'tenant_id'       => $this->tenant->id,
            'site_id'         => $this->site->id,
            'attendance_date' => $today,
            'participant_id'  => $this->participant->id,
        ]);

        $this->actingAs($this->activitiesUser)
            ->getJson("/scheduling/day-center/summary?from={$today}&to={$today}&site_id={$this->site->id}")
            ->assertOk()
            ->assertJsonStructure(['summary']);
    }
}

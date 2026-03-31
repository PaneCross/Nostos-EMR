<?php

// ─── QaMetricsServiceTest ───────────────────────────────────────────────────────
// Unit tests for QaMetricsService — verifies each KPI computation.
//
// Coverage:
//   - getSdrComplianceRate(): 100% when no SDRs, correct % with mix of compliant/late
//   - getOverdueAssessments(): only past next_due_date, not future or null
//   - getUnsignedNotesOlderThan(): only drafts older than threshold, not recent/signed
//   - getOpenIncidents(): only non-closed incidents
//   - getCarePlansOverdue(): only non-archived with past review_due_date
//   - getHospitalizationsThisMonth(): counts hosp+er_visit this month only
//   - All methods are tenant-scoped (cross-tenant data excluded)
// ──────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Models\Assessment;
use App\Models\CarePlan;
use App\Models\ClinicalNote;
use App\Models\Incident;
use App\Models\Participant;
use App\Models\Sdr;
use App\Models\User;
use App\Services\QaMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QaMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    private QaMetricsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QaMetricsService();
    }

    private function makeUser(string $dept = 'qa_compliance'): User
    {
        return User::factory()->create(['department' => $dept]);
    }

    private function makeParticipant(User $user): Participant
    {
        return Participant::factory()->create(['tenant_id' => $user->tenant_id]);
    }

    // ── getSdrComplianceRate() ────────────────────────────────────────────────

    public function test_sdr_compliance_rate_is_100_when_no_sdrs(): void
    {
        $user = $this->makeUser();

        $rate = $this->service->getSdrComplianceRate($user->tenant_id);

        $this->assertEquals(100.0, $rate);
    }

    public function test_sdr_compliance_rate_100_percent_all_on_time(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        // 3 SDRs all completed before due_at
        Sdr::factory()->count(3)->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'completed',
            'submitted_at'   => now()->subDays(5),
            'due_at'         => now()->subDays(2),
            'completed_at'   => now()->subDays(3), // before due_at
        ]);

        $rate = $this->service->getSdrComplianceRate($user->tenant_id);

        $this->assertEquals(100.0, $rate);
    }

    public function test_sdr_compliance_rate_0_percent_all_late(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        // 2 SDRs completed AFTER due_at (late)
        Sdr::factory()->count(2)->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'completed',
            'submitted_at'   => now()->subDays(10),
            'due_at'         => now()->subDays(7),
            'completed_at'   => now()->subDays(5), // after due_at → non-compliant
        ]);

        $rate = $this->service->getSdrComplianceRate($user->tenant_id);

        $this->assertEquals(0.0, $rate);
    }

    public function test_sdr_compliance_rate_mixed(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        // 2 compliant
        Sdr::factory()->count(2)->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'completed',
            'submitted_at'   => now()->subDays(5),
            'due_at'         => now()->subDays(2),
            'completed_at'   => now()->subDays(3),
        ]);

        // 2 late
        Sdr::factory()->count(2)->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'completed',
            'submitted_at'   => now()->subDays(10),
            'due_at'         => now()->subDays(7),
            'completed_at'   => now()->subDays(5),
        ]);

        $rate = $this->service->getSdrComplianceRate($user->tenant_id);

        $this->assertEquals(50.0, $rate);
    }

    // ── getOverdueAssessments() ───────────────────────────────────────────────

    public function test_overdue_assessments_returns_past_due(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        Assessment::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'next_due_date'  => now()->subDays(3)->toDateString(),
        ]);

        $result = $this->service->getOverdueAssessments($user->tenant_id);

        $this->assertCount(1, $result);
    }

    public function test_overdue_assessments_excludes_future_due(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        Assessment::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'next_due_date'  => now()->addDays(10)->toDateString(),
        ]);

        $result = $this->service->getOverdueAssessments($user->tenant_id);

        $this->assertCount(0, $result);
    }

    public function test_overdue_assessments_excludes_null_due_date(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        Assessment::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'next_due_date'  => null,
        ]);

        $result = $this->service->getOverdueAssessments($user->tenant_id);

        $this->assertCount(0, $result);
    }

    public function test_overdue_assessments_is_tenant_scoped(): void
    {
        $user      = $this->makeUser();
        $otherUser = User::factory()->create();

        Assessment::factory()->create([
            'tenant_id'      => $otherUser->tenant_id,
            'participant_id' => $this->makeParticipant($otherUser)->id,
            'next_due_date'  => now()->subDays(3)->toDateString(),
        ]);

        $result = $this->service->getOverdueAssessments($user->tenant_id);

        $this->assertCount(0, $result);
    }

    // ── getUnsignedNotesOlderThan() ───────────────────────────────────────────

    public function test_unsigned_notes_returns_old_drafts(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        ClinicalNote::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'draft',
            'created_at'     => now()->subHours(30),
        ]);

        $result = $this->service->getUnsignedNotesOlderThan($user->tenant_id, 24);

        $this->assertCount(1, $result);
    }

    public function test_unsigned_notes_excludes_recent_drafts(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        ClinicalNote::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'draft',
            'created_at'     => now()->subHours(12), // within 24h window
        ]);

        $result = $this->service->getUnsignedNotesOlderThan($user->tenant_id, 24);

        $this->assertCount(0, $result);
    }

    public function test_unsigned_notes_excludes_signed_notes(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        ClinicalNote::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'signed',
            'created_at'     => now()->subHours(48),
        ]);

        $result = $this->service->getUnsignedNotesOlderThan($user->tenant_id, 24);

        $this->assertCount(0, $result);
    }

    // ── getOpenIncidents() ────────────────────────────────────────────────────

    public function test_open_incidents_excludes_closed(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        Incident::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'open',
        ]);
        Incident::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'closed',
        ]);

        $result = $this->service->getOpenIncidents($user->tenant_id);

        $this->assertCount(1, $result);
        $this->assertEquals('open', $result->first()->status);
    }

    public function test_open_incidents_is_tenant_scoped(): void
    {
        $user      = $this->makeUser();
        $otherUser = User::factory()->create();

        Incident::factory()->create([
            'tenant_id'      => $otherUser->tenant_id,
            'participant_id' => $this->makeParticipant($otherUser)->id,
            'status'         => 'open',
        ]);

        $result = $this->service->getOpenIncidents($user->tenant_id);

        $this->assertCount(0, $result);
    }

    // ── getCarePlansOverdue() ─────────────────────────────────────────────────

    public function test_overdue_care_plans_excludes_archived(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        // Archived care plan with past due date — should NOT appear
        CarePlan::factory()->create([
            'tenant_id'       => $user->tenant_id,
            'participant_id'  => $participant->id,
            'status'          => 'archived',
            'review_due_date' => now()->subDays(10)->toDateString(),
        ]);

        $result = $this->service->getCarePlansOverdue($user->tenant_id);

        $this->assertCount(0, $result);
    }

    public function test_overdue_care_plans_returns_non_archived_past_due(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        CarePlan::factory()->create([
            'tenant_id'       => $user->tenant_id,
            'participant_id'  => $participant->id,
            'status'          => 'active',
            'review_due_date' => now()->subDays(5)->toDateString(),
        ]);

        $result = $this->service->getCarePlansOverdue($user->tenant_id);

        $this->assertCount(1, $result);
    }

    // ── getHospitalizationsThisMonth() ────────────────────────────────────────

    public function test_hospitalizations_counts_this_month(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        Incident::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'incident_type'  => 'hospitalization',
            'occurred_at'    => now()->startOfMonth()->addDays(3),
        ]);

        $count = $this->service->getHospitalizationsThisMonth($user->tenant_id);

        $this->assertEquals(1, $count);
    }

    public function test_hospitalizations_includes_er_visits(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        Incident::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'incident_type'  => 'er_visit',
            'occurred_at'    => now()->startOfMonth()->addDays(1),
        ]);

        $count = $this->service->getHospitalizationsThisMonth($user->tenant_id);

        $this->assertEquals(1, $count);
    }

    public function test_hospitalizations_excludes_prior_month(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        Incident::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'incident_type'  => 'hospitalization',
            // Use subMonths(2) to avoid edge-case failures on month-end boundary dates
            // (e.g. March 31 → subMonth() = Feb 28 which is unambiguously prior month,
            // but subMonths(2) = Jan 31 makes it clearer and avoids any DST/tz edge cases)
            'occurred_at'    => now()->subMonths(2),
        ]);

        $count = $this->service->getHospitalizationsThisMonth($user->tenant_id);

        $this->assertEquals(0, $count);
    }
}

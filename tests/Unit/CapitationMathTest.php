<?php

// ─── CapitationMathTest ───────────────────────────────────────────────────────
// W3-7: Unit tests for billing math calculations.
//
// Verifies:
//   1. Monthly total = sum of component rates per participant
//   2. Org-wide total = sum of all participant totals for the month
//   3. Encounter submission rate = (submitted + accepted) / total * 100
//   4. HOS-M completion rate = completed / enrolled * 100
//   5. Risk score average = sum(scores) / count
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Models\CapitationRecord;
use App\Models\EncounterLog;
use App\Models\HosMSurvey;
use App\Models\Participant;
use App\Models\ParticipantRiskScore;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapitationMathTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeParticipant(): Participant
    {
        return Participant::factory()->create();
    }

    private function makeUser(?int $tenantId = null): User
    {
        return User::factory()->create(
            $tenantId ? ['tenant_id' => $tenantId] : []
        );
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * Monthly total_capitation = medicare_ab_rate + medicare_d_rate + medicaid_rate
     */
    public function test_monthly_total_is_sum_of_three_rate_components(): void
    {
        $participant = $this->makeParticipant();

        $abRate  = 3500.00;
        $dRate   = 250.00;
        $medRate = 2000.00;
        $total   = $abRate + $dRate + $medRate; // 5750.00

        $record = CapitationRecord::create([
            'tenant_id'        => $participant->tenant_id,
            'participant_id'   => $participant->id,
            'month_year'       => now()->format('Y-m'),
            'medicare_ab_rate' => $abRate,
            'medicare_a_rate'  => round($abRate * 0.68, 2),
            'medicare_b_rate'  => round($abRate * 0.32, 2),
            'medicare_d_rate'  => $dRate,
            'medicaid_rate'    => $medRate,
            'total_capitation' => $total,
            'recorded_at'      => now(),
        ]);

        $this->assertEquals(
            $total,
            (float) $record->medicare_ab_rate + (float) $record->medicare_d_rate + (float) $record->medicaid_rate
        );
        $this->assertEquals($total, (float) $record->total_capitation);
    }

    /**
     * Org-wide capitation total = sum of all participant totals for the month
     */
    public function test_org_wide_total_is_sum_of_all_participant_totals(): void
    {
        $user        = $this->makeUser();
        $tenantId    = $user->tenant_id;
        $monthYear   = now()->format('Y-m');

        $participants = Participant::factory()->count(3)->create(['tenant_id' => $tenantId]);

        $totals = [3000.00, 4500.00, 5250.00];
        foreach ($participants as $idx => $participant) {
            CapitationRecord::create([
                'tenant_id'        => $tenantId,
                'participant_id'   => $participant->id,
                'month_year'       => $monthYear,
                'medicare_ab_rate' => 2000.00,
                'medicare_d_rate'  => 250.00,
                'medicaid_rate'    => $totals[$idx] - 2250.00,
                'total_capitation' => $totals[$idx],
                'recorded_at'      => now(),
            ]);
        }

        $orgTotal = CapitationRecord::where('tenant_id', $tenantId)
            ->where('month_year', $monthYear)
            ->sum('total_capitation');

        $expectedTotal = array_sum($totals);
        $this->assertEquals($expectedTotal, (float) $orgTotal);
    }

    /**
     * Encounter submission rate = (submitted + accepted) / total * 100
     */
    public function test_encounter_submission_rate_is_submitted_plus_accepted_over_total(): void
    {
        $user        = $this->makeUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);

        // Create 10 encounters: 3 pending, 6 submitted, 1 accepted
        $statuses = array_merge(
            array_fill(0, 3, 'pending'),
            array_fill(0, 6, 'submitted'),
            array_fill(0, 1, 'accepted'),
        );

        foreach ($statuses as $status) {
            EncounterLog::create([
                'tenant_id'      => $user->tenant_id,
                'participant_id' => $participant->id,
                'service_date'   => now()->toDateString(),
                'service_type'   => 'outpatient',
                'procedure_code' => '99213',
                'claim_type'     => 'internal_capitated',
                'submission_status' => $status,
                'created_by_user_id' => $user->id,
            ]);
        }

        $total      = EncounterLog::where('tenant_id', $user->tenant_id)->count();
        $submitted  = EncounterLog::where('tenant_id', $user->tenant_id)
            ->whereIn('submission_status', ['submitted', 'accepted'])
            ->count();

        $rate = $total > 0 ? round($submitted / $total * 100, 1) : 0;

        $this->assertEquals(10, $total);
        $this->assertEquals(7, $submitted);
        $this->assertEquals(70.0, $rate);
    }

    /**
     * HOS-M completion rate = completed / enrolled * 100
     */
    public function test_hos_m_completion_rate_is_completed_over_enrolled(): void
    {
        $user        = $this->makeUser();
        $year        = now()->year;
        $participants = Participant::factory()->count(10)->create(['tenant_id' => $user->tenant_id]);

        // 8 completed, 2 incomplete
        foreach ($participants as $i => $participant) {
            HosMSurvey::create([
                'tenant_id'               => $user->tenant_id,
                'participant_id'          => $participant->id,
                'survey_year'             => $year,
                'administered_by_user_id' => $user->id,
                'administered_at'         => now()->subDays(30),
                'completed'               => $i < 8,
                'submitted_to_cms'        => $i < 7,
            ]);
        }

        $total     = HosMSurvey::where('tenant_id', $user->tenant_id)
            ->where('survey_year', $year)
            ->count();
        $completed = HosMSurvey::where('tenant_id', $user->tenant_id)
            ->where('survey_year', $year)
            ->where('completed', true)
            ->count();

        $rate = $total > 0 ? round($completed / $total * 100, 1) : 0;

        $this->assertEquals(10, $total);
        $this->assertEquals(8, $completed);
        $this->assertEquals(80.0, $rate);
    }

    /**
     * Risk score average = sum(scores) / count
     */
    public function test_risk_score_average_is_sum_over_count(): void
    {
        $user        = $this->makeUser();
        $year        = now()->year;
        $participants = Participant::factory()->count(4)->create(['tenant_id' => $user->tenant_id]);

        $scores = [1.5, 2.0, 3.0, 3.5]; // avg = 2.5
        foreach ($participants as $i => $participant) {
            ParticipantRiskScore::create([
                'tenant_id'      => $user->tenant_id,
                'participant_id' => $participant->id,
                'payment_year'   => $year,
                'risk_score'     => $scores[$i],
                'score_source'   => 'calculated',
                'effective_date' => now()->startOfYear()->toDateString(),
            ]);
        }

        $avg = ParticipantRiskScore::where('tenant_id', $user->tenant_id)
            ->where('payment_year', $year)
            ->avg('risk_score');

        $this->assertEquals(2.5, round((float) $avg, 1));
    }
}

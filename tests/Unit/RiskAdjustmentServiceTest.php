<?php

// ─── RiskAdjustmentServiceTest ────────────────────────────────────────────────
// Unit tests for the Phase 9C RiskAdjustmentService.
//
// Coverage:
//   - test_get_diagnoses_returns_empty_for_participant_with_no_problems
//   - test_get_diagnoses_marks_submitted_codes
//   - test_update_risk_score_upserts_record
//   - test_update_risk_score_updates_existing_record
//   - test_get_risk_adjustment_gaps_delegates_to_hcc_service
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Models\EncounterLog;
use App\Models\Participant;
use App\Models\ParticipantRiskScore;
use App\Models\Problem;
use App\Services\HccRiskScoringService;
use App\Services\RiskAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskAdjustmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): RiskAdjustmentService
    {
        return new RiskAdjustmentService(new HccRiskScoringService());
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_get_diagnoses_returns_empty_for_participant_with_no_problems(): void
    {
        $participant = Participant::factory()->create();

        $result = $this->makeService()->getDiagnosesForRiskSubmission($participant->id, now()->year);

        $this->assertEquals($participant->id, $result['participant_id']);
        $this->assertEmpty($result['diagnoses']);
    }

    public function test_get_diagnoses_returns_active_problem_codes(): void
    {
        $participant = Participant::factory()->create();

        Problem::factory()->create([
            'participant_id' => $participant->id,
            'icd10_code'     => 'E11.65',
            'resolved_date'  => null,
        ]);

        $result = $this->makeService()->getDiagnosesForRiskSubmission($participant->id, now()->year);

        $this->assertNotEmpty($result['diagnoses']);
        $codes = array_column($result['diagnoses'], 'icd10_code');
        $this->assertContains('E11.65', $codes);
    }

    public function test_resolved_problems_are_excluded(): void
    {
        $participant = Participant::factory()->create();

        Problem::factory()->create([
            'participant_id' => $participant->id,
            'icd10_code'     => 'J18.9',
            'resolved_date'  => now()->subMonth(),
        ]);

        $result = $this->makeService()->getDiagnosesForRiskSubmission($participant->id, now()->year);

        $codes = array_column($result['diagnoses'], 'icd10_code');
        $this->assertNotContains('J18.9', $codes);
    }

    public function test_get_diagnoses_marks_submitted_codes_correctly(): void
    {
        $participant = Participant::factory()->create();

        Problem::factory()->create([
            'participant_id' => $participant->id,
            'icd10_code'     => 'I50.9',
            'resolved_date'  => null,
        ]);

        // Create an accepted encounter with this diagnosis code
        EncounterLog::factory()->create([
            'participant_id'    => $participant->id,
            'tenant_id'         => $participant->tenant_id,
            'diagnosis_codes'   => ['I509'],  // normalized (no dot)
            'submission_status' => 'accepted',
            'service_date'      => now()->format('Y-m-d'),
        ]);

        $result = $this->makeService()->getDiagnosesForRiskSubmission($participant->id, now()->year);

        $found = collect($result['diagnoses'])->firstWhere('icd10_code', 'I50.9');
        $this->assertNotNull($found);
        $this->assertTrue($found['already_submitted']);
    }

    public function test_update_risk_score_creates_new_record(): void
    {
        $participant = Participant::factory()->create();

        $score = $this->makeService()->updateParticipantRiskScore($participant->id, now()->year);

        $this->assertInstanceOf(ParticipantRiskScore::class, $score);
        $this->assertEquals($participant->id, $score->participant_id);
        $this->assertEquals(now()->year, $score->payment_year);
        $this->assertEquals('calculated', $score->score_source);
    }

    public function test_update_risk_score_updates_existing_record(): void
    {
        $participant = Participant::factory()->create();

        // Create an existing record
        $existing = ParticipantRiskScore::factory()->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $participant->tenant_id,
            'payment_year'   => now()->year,
            'risk_score'     => 9.9999,
        ]);

        // Recalculate — should update not duplicate
        $updated = $this->makeService()->updateParticipantRiskScore($participant->id, now()->year);

        $this->assertEquals($existing->id, $updated->id);
        $this->assertNotEquals(9.9999, $updated->risk_score); // was overwritten by calculation
    }

    public function test_get_risk_adjustment_gaps_returns_array(): void
    {
        $participant = Participant::factory()->create(['enrollment_status' => 'enrolled']);

        $result = $this->makeService()->getRiskAdjustmentGaps($participant->tenant_id);

        $this->assertArrayHasKey('total_participants', $result);
        $this->assertArrayHasKey('participants_with_gaps', $result);
        $this->assertArrayHasKey('total_gap_count', $result);
        $this->assertArrayHasKey('estimated_monthly_revenue_at_risk', $result);
        $this->assertArrayHasKey('top_gaps', $result);
    }
}

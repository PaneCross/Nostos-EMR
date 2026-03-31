<?php

// ─── BillingDemoDataTest ──────────────────────────────────────────────────────
// W3-7: Verifies that BillingDemoSeeder produces complete, coherent billing data.
//
// Coverage:
//   - test_capitation_records_exist_for_all_enrolled_participants
//   - test_risk_scores_exist_for_all_enrolled_participants
//   - test_encounter_log_has_records_for_all_enrolled_participants
//   - test_hos_m_surveys_exist_for_all_enrolled_participants
//   - test_edi_batch_is_seeded_in_acknowledged_status
//   - test_pde_records_are_seeded
//   - test_capitation_covers_three_months
//   - test_encounter_submission_rate_is_approximately_70_percent
//   - test_hos_m_completion_rate_is_approximately_83_percent
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\CapitationRecord;
use App\Models\EdiBatch;
use App\Models\EncounterLog;
use App\Models\HosMSurvey;
use App\Models\Participant;
use App\Models\ParticipantRiskScore;
use App\Models\PdeRecord;
use App\Models\Tenant;
use Database\Seeders\BillingDemoSeeder;
use Database\Seeders\DemoEnvironmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingDemoDataTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        // Run the full demo seeder once per test class (via RefreshDatabase + setUp)
        $this->artisan('db:seed', ['--class' => DemoEnvironmentSeeder::class])->assertExitCode(0);
        $this->tenantId = Tenant::where('slug', 'sunrise-pace-demo')->firstOrFail()->id;
    }

    // ── Coverage tests ────────────────────────────────────────────────────────

    public function test_capitation_records_exist_for_all_enrolled_participants(): void
    {
        $enrolled = Participant::where('tenant_id', $this->tenantId)
            ->where('enrollment_status', 'enrolled')
            ->pluck('id');

        $this->assertGreaterThan(0, $enrolled->count(), 'Should have enrolled participants');

        foreach ($enrolled as $participantId) {
            $count = CapitationRecord::where('tenant_id', $this->tenantId)
                ->where('participant_id', $participantId)
                ->count();

            $this->assertGreaterThanOrEqual(
                3,
                $count,
                "Participant {$participantId} should have at least 3 capitation records"
            );
        }
    }

    public function test_risk_scores_exist_for_all_enrolled_participants(): void
    {
        $enrolled = Participant::where('tenant_id', $this->tenantId)
            ->where('enrollment_status', 'enrolled')
            ->pluck('id');

        foreach ($enrolled as $participantId) {
            $exists = ParticipantRiskScore::where('tenant_id', $this->tenantId)
                ->where('participant_id', $participantId)
                ->where('payment_year', now()->year)
                ->exists();

            $this->assertTrue($exists, "Participant {$participantId} should have a risk score for current year");
        }
    }

    public function test_encounter_log_has_records_for_all_enrolled_participants(): void
    {
        $enrolled = Participant::where('tenant_id', $this->tenantId)
            ->where('enrollment_status', 'enrolled')
            ->pluck('id');

        foreach ($enrolled as $participantId) {
            $count = EncounterLog::where('tenant_id', $this->tenantId)
                ->where('participant_id', $participantId)
                ->count();

            $this->assertGreaterThanOrEqual(
                15,
                $count,
                "Participant {$participantId} should have at least 15 encounter records"
            );
        }
    }

    public function test_hos_m_surveys_exist_for_all_enrolled_participants(): void
    {
        $enrolled = Participant::where('tenant_id', $this->tenantId)
            ->where('enrollment_status', 'enrolled')
            ->pluck('id');

        foreach ($enrolled as $participantId) {
            $exists = HosMSurvey::where('tenant_id', $this->tenantId)
                ->where('participant_id', $participantId)
                ->where('survey_year', now()->year)
                ->exists();

            $this->assertTrue($exists, "Participant {$participantId} should have a HOS-M survey");
        }
    }

    public function test_edi_batch_is_seeded_in_acknowledged_status(): void
    {
        $batch = EdiBatch::where('tenant_id', $this->tenantId)
            ->where('status', 'acknowledged')
            ->first();

        $this->assertNotNull($batch, 'At least one acknowledged EDI batch should exist');
        $this->assertEquals('edr', $batch->batch_type);
        $this->assertNotNull($batch->file_content);
        $this->assertGreaterThan(0, $batch->record_count);
    }

    public function test_pde_records_are_seeded(): void
    {
        $pdeCount = PdeRecord::where('tenant_id', $this->tenantId)->count();
        $this->assertGreaterThan(0, $pdeCount, 'PDE records should be seeded');

        // Every 3rd participant gets PDEs (≥ 3 per participant, at least 10 participants worth)
        $this->assertGreaterThanOrEqual(10, $pdeCount, 'Should have at least 10 PDE records total');
    }

    public function test_capitation_covers_three_months(): void
    {
        $months = CapitationRecord::where('tenant_id', $this->tenantId)
            ->distinct('month_year')
            ->pluck('month_year')
            ->unique();

        $this->assertGreaterThanOrEqual(3, $months->count(), 'Should have capitation records for at least 3 months');
    }

    public function test_encounter_submission_rate_is_approximately_70_percent(): void
    {
        $total      = EncounterLog::where('tenant_id', $this->tenantId)->count();
        $submitted  = EncounterLog::where('tenant_id', $this->tenantId)
            ->whereIn('submission_status', ['submitted', 'accepted'])
            ->count();

        $this->assertGreaterThan(0, $total);

        $rate = $submitted / $total * 100;
        // Seeded with 70% target; allow 55-85% band (randomness in seeder)
        $this->assertGreaterThanOrEqual(55, $rate, "Submission rate ({$rate}%) should be at least 55%");
        $this->assertLessThanOrEqual(85, $rate, "Submission rate ({$rate}%) should be at most 85%");
    }

    public function test_hos_m_completion_rate_is_approximately_83_percent(): void
    {
        $total     = HosMSurvey::where('tenant_id', $this->tenantId)
            ->where('survey_year', now()->year)
            ->count();
        $completed = HosMSurvey::where('tenant_id', $this->tenantId)
            ->where('survey_year', now()->year)
            ->where('completed', true)
            ->count();

        $this->assertGreaterThan(0, $total);

        $rate = $completed / $total * 100;
        // Seeder targets exactly 25/30 = 83.3% — allow 75-92% band
        $this->assertGreaterThanOrEqual(75, $rate, "HOS-M completion ({$rate}%) should be at least 75%");
        $this->assertLessThanOrEqual(92, $rate, "HOS-M completion ({$rate}%) should be at most 92%");
    }
}

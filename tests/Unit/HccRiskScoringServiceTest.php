<?php

// ─── HccRiskScoringServiceTest ────────────────────────────────────────────────
// Unit tests for HccRiskScoringService.
//
// Coverage:
//   - test_calculate_raf_score_returns_zero_when_no_active_diagnoses
//   - test_calculate_raf_score_maps_icd10_to_hcc
//   - test_calculate_raf_score_accumulates_multiple_hcc_categories
//   - test_find_hcc_gaps_returns_unsubmitted_diagnoses
//   - test_org_wide_gap_summary_returns_array_structure
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Models\HccMapping;
use App\Models\Participant;
use App\Models\Problem;
use App\Models\User;
use App\Services\HccRiskScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HccRiskScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private HccRiskScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(HccRiskScoringService::class);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_calculate_raf_score_returns_zero_when_no_active_diagnoses(): void
    {
        $user        = User::factory()->create(['department' => 'primary_care']);
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);

        $result = $this->service->calculateRafScore($participant->id, 2025);

        $this->assertEquals(0.0, $result['raf_score']);
        $this->assertEmpty($result['hcc_categories']);
        $this->assertEmpty($result['mapped_diagnoses']);
    }

    public function test_calculate_raf_score_maps_icd10_to_hcc(): void
    {
        $user        = User::factory()->create(['department' => 'primary_care']);
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);

        // Seed an HCC mapping for a known ICD-10 code
        HccMapping::factory()->create([
            'icd10_code'     => 'E119',
            'hcc_category'   => '19',
            'hcc_label'      => 'Diabetes without Complication',
            'raf_value'      => 0.1050,
            'effective_year' => 2025,
        ]);

        // Create an active problem with that diagnosis code
        Problem::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'icd10_code'     => 'E119',
            'resolved_date'  => null, // active
        ]);

        $result = $this->service->calculateRafScore($participant->id, 2025);

        $this->assertGreaterThan(0, $result['raf_score']);
        $categoryIds = array_column($result['hcc_categories'], 'hcc_category');
        $this->assertContains('19', $categoryIds);
    }

    public function test_calculate_raf_score_accumulates_multiple_hcc_categories(): void
    {
        $user        = User::factory()->create(['department' => 'primary_care']);
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);

        // Two distinct HCC mappings
        HccMapping::factory()->create([
            'icd10_code'     => 'E119',
            'hcc_category'   => '19',
            'raf_value'      => 0.1050,
            'effective_year' => 2025,
        ]);
        HccMapping::factory()->create([
            'icd10_code'     => 'I50.9',
            'hcc_category'   => '85',
            'raf_value'      => 0.3310,
            'effective_year' => 2025,
        ]);

        Problem::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'icd10_code'     => 'E119',
            'resolved_date'  => null,
        ]);
        Problem::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'icd10_code'     => 'I50.9',
            'resolved_date'  => null,
        ]);

        $result = $this->service->calculateRafScore($participant->id, 2025);

        // RAF score should be sum of both HCC values
        $this->assertEqualsWithDelta(0.1050 + 0.3310, $result['raf_score'], 0.0001);
        $categoryIds = array_column($result['hcc_categories'], 'hcc_category');
        $this->assertContains('19', $categoryIds);
        $this->assertContains('85', $categoryIds);
    }

    public function test_find_hcc_gaps_returns_unsubmitted_diagnoses(): void
    {
        $user        = User::factory()->create(['department' => 'primary_care']);
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);

        HccMapping::factory()->create([
            'icd10_code'     => 'E119',
            'hcc_category'   => '19',
            'hcc_label'      => 'Diabetes without Complication',
            'raf_value'      => 0.1050,
            'effective_year' => 2025,
        ]);

        // Problem exists in chart but NOT in any encounter's diagnosis_codes
        Problem::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'icd10_code'     => 'E119',
            'resolved_date'  => null,
        ]);

        $gaps = $this->service->findHccGaps($participant->id, 2025);

        $this->assertNotEmpty($gaps);
        $gapCodes = array_column($gaps, 'icd10_code');
        $this->assertContains('E119', $gapCodes);
    }

    public function test_org_wide_gap_summary_returns_array_structure(): void
    {
        $user = User::factory()->create(['department' => 'finance']);

        $summary = $this->service->getOrgWideGapSummary($user->tenant_id, 2025);

        $this->assertIsArray($summary);
        // Org-wide summary is a scalar aggregate (not a list of items)
        $this->assertArrayHasKey('total_participants', $summary);
        $this->assertArrayHasKey('participants_with_gaps', $summary);
        $this->assertArrayHasKey('total_gap_count', $summary);
        $this->assertArrayHasKey('estimated_monthly_revenue_at_risk', $summary);
        $this->assertArrayHasKey('top_gaps', $summary);
    }
}

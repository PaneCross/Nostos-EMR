<?php

// ─── LaceIndexTest ────────────────────────────────────────────────────────────
// Verifies W4-8: lace_plus_index assessment type (dual-threshold alert logic).
//
// LACE+ Index readmission risk:
//   Score >= 10 → Critical alert (High Risk)
//   Score 5–9   → Warning alert (Moderate Risk)
//   Score < 5   → No alert (Low Risk)
//
// Tests:
//   - Score >= 10 creates a CRITICAL alert
//   - Score 5–9 creates a WARNING alert
//   - Score = 4 creates NO alert
//   - Score = 0 creates NO alert
//   - scoredLabel() returns correct risk tier labels
//   - typeLabel() returns 'LACE+ Index (Readmission Risk)'
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Assessment;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaceIndexTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private Participant $participant;
    private User        $clinicalUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->participant = Participant::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'site_id'           => $this->site->id,
            'enrollment_status' => 'enrolled',
        ]);

        $this->clinicalUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
    }

    private function postLaceAssessment(int $score): void
    {
        $this->actingAs($this->clinicalUser)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'lace_plus_index',
                'score'           => $score,
                'completed_at'    => now()->toIso8601String(),
                'responses'       => [
                    'length_of_stay'  => $score >= 5 ? 3 : 1,
                    'acuity'          => $score >= 10 ? 3 : 0,
                    'comorbidity'     => 2,
                    'ed_visits'       => 1,
                ],
            ])
            ->assertCreated();
    }

    public function test_score_of_10_creates_critical_alert(): void
    {
        $this->postLaceAssessment(10);

        $alert = Alert::where('alert_type', 'assessment_lace_plus_index_threshold')->first();

        $this->assertNotNull($alert, 'LACE+ score 10 should create an alert');
        $this->assertEquals('critical', $alert->severity);
    }

    public function test_score_of_15_creates_critical_alert(): void
    {
        $this->postLaceAssessment(15);

        $alert = Alert::where('alert_type', 'assessment_lace_plus_index_threshold')->first();
        $this->assertEquals('critical', $alert->severity);
    }

    public function test_score_of_5_creates_warning_alert(): void
    {
        $this->postLaceAssessment(5);

        $alert = Alert::where('alert_type', 'assessment_lace_plus_index_threshold')->first();

        $this->assertNotNull($alert, 'LACE+ score 5 should create an alert');
        $this->assertEquals('warning', $alert->severity);
    }

    public function test_score_of_9_creates_warning_alert(): void
    {
        $this->postLaceAssessment(9);

        $alert = Alert::where('alert_type', 'assessment_lace_plus_index_threshold')->first();
        $this->assertEquals('warning', $alert->severity);
    }

    public function test_score_of_4_creates_no_alert(): void
    {
        $this->postLaceAssessment(4);

        $this->assertEquals(0, Alert::where('alert_type', 'assessment_lace_plus_index_threshold')->count());
    }

    public function test_score_of_0_creates_no_alert(): void
    {
        $this->postLaceAssessment(0);

        $this->assertEquals(0, Alert::where('alert_type', 'assessment_lace_plus_index_threshold')->count());
    }

    public function test_lace_scored_label_high_risk(): void
    {
        $assessment = new Assessment(['assessment_type' => 'lace_plus_index', 'score' => 12]);
        $this->assertStringContainsString('High Risk', $assessment->scoredLabel());
    }

    public function test_lace_scored_label_moderate_risk(): void
    {
        $assessment = new Assessment(['assessment_type' => 'lace_plus_index', 'score' => 7]);
        $this->assertStringContainsString('Moderate Risk', $assessment->scoredLabel());
    }

    public function test_lace_scored_label_low_risk(): void
    {
        $assessment = new Assessment(['assessment_type' => 'lace_plus_index', 'score' => 3]);
        $this->assertStringContainsString('Low Risk', $assessment->scoredLabel());
    }

    public function test_lace_type_label(): void
    {
        $assessment = new Assessment(['assessment_type' => 'lace_plus_index']);
        $this->assertEquals('LACE+ Index (Readmission Risk)', $assessment->typeLabel());
    }
}

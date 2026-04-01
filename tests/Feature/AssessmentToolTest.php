<?php

// ─── AssessmentToolTest (W4-4) ─────────────────────────────────────────────────
// Tests Braden Scale, MoCA, and OHAT assessment types + alert threshold logic.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Assessment;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AssessmentToolTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $user;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'AST',
        ]);
        $this->user = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();

        // Fake events AFTER DB fixtures are created so Eloquent model events
        // (e.g. Participant::creating MRN generation) are not intercepted.
        Event::fake();
    }

    // ─── New assessment types stored correctly ────────────────────────────────

    public function test_braden_scale_assessment_stored(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'braden_scale',
                'score'           => 18,
                'completed_at'    => today()->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('assessment_type', 'braden_scale')
            ->assertJsonPath('score', 18);
    }

    public function test_moca_cognitive_assessment_stored(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'moca_cognitive',
                'score'           => 24,
                'completed_at'    => today()->toDateString(),
            ])
            ->assertCreated();
    }

    public function test_oral_health_assessment_stored(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'oral_health',
                'score'           => 6,
                'completed_at'    => today()->toDateString(),
            ])
            ->assertCreated();
    }

    // ─── Alert thresholds ─────────────────────────────────────────────────────

    public function test_braden_score_at_14_creates_alert(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'braden_scale',
                'score'           => 14,
                'completed_at'    => today()->toDateString(),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('emr_alerts', [
            'participant_id' => $this->participant->id,
            'alert_type'     => 'assessment_braden_scale_threshold',
            'severity'       => 'warning',
        ]);
    }

    public function test_braden_score_above_14_no_alert(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'braden_scale',
                'score'           => 15,
                'completed_at'    => today()->toDateString(),
            ])
            ->assertCreated();

        $this->assertDatabaseMissing('emr_alerts', [
            'participant_id' => $this->participant->id,
            'alert_type'     => 'assessment_braden_scale_threshold',
        ]);
    }

    public function test_moca_score_below_26_creates_alert(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'moca_cognitive',
                'score'           => 22,
                'completed_at'    => today()->toDateString(),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('emr_alerts', [
            'participant_id' => $this->participant->id,
            'alert_type'     => 'assessment_moca_cognitive_threshold',
            'severity'       => 'warning',
        ]);
    }

    public function test_ohat_score_above_8_creates_alert(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'oral_health',
                'score'           => 10,
                'completed_at'    => today()->toDateString(),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('emr_alerts', [
            'participant_id' => $this->participant->id,
            'alert_type'     => 'assessment_oral_health_threshold',
            'severity'       => 'warning',
        ]);
    }

    // ─── Model helpers ────────────────────────────────────────────────────────

    public function test_braden_scored_label(): void
    {
        $a = new Assessment(['assessment_type' => 'braden_scale', 'score' => 12]);
        $this->assertStringContainsString('High Risk', $a->scoredLabel());
    }

    public function test_moca_scored_label_normal(): void
    {
        $a = new Assessment(['assessment_type' => 'moca_cognitive', 'score' => 28]);
        $this->assertStringContainsString('Normal', $a->scoredLabel());
    }

    public function test_oral_health_type_label(): void
    {
        $a = new Assessment(['assessment_type' => 'oral_health', 'score' => 4]);
        $this->assertStringContainsString('OHAT', $a->typeLabel());
    }
}

<?php

// ─── FallHistoryTest ──────────────────────────────────────────────────────────
// Verifies W4-8: fall_history assessment type.
//
// Tests:
//   - fall_history assessment can be stored with responses
//   - falls_12_months >= 2 creates a warning alert for primary_care + idt
//   - falls_12_months < 2 does NOT create an alert
//   - falls_12_months = 0 does NOT create an alert
//   - Alert contains the correct fall count in the message
//   - Assessment type label returns 'Fall History Screen'
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

class FallHistoryTest extends TestCase
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

    public function test_fall_history_assessment_stores_responses(): void
    {
        $response = $this->actingAs($this->clinicalUser)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'fall_history',
                'completed_at'    => now()->toIso8601String(),
                'responses'       => [
                    'falls_12_months'     => 3,
                    'fall_resulted_injury'=> true,
                    'last_fall_date'      => now()->subDays(14)->toDateString(),
                ],
            ]);

        $response->assertCreated();

        $assessment = Assessment::where('participant_id', $this->participant->id)
            ->where('assessment_type', 'fall_history')
            ->first();

        $this->assertNotNull($assessment);
        $this->assertEquals(3, $assessment->responses['falls_12_months']);
        $this->assertTrue($assessment->responses['fall_resulted_injury']);
    }

    public function test_two_or_more_falls_creates_warning_alert(): void
    {
        $this->actingAs($this->clinicalUser)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'fall_history',
                'completed_at'    => now()->toIso8601String(),
                'responses'       => ['falls_12_months' => 2],
            ])
            ->assertCreated();

        $alert = Alert::where('tenant_id', $this->tenant->id)
            ->where('alert_type', 'assessment_fall_history_threshold')
            ->first();

        $this->assertNotNull($alert, 'A warning alert should be created for 2+ falls');
        $this->assertEquals('warning', $alert->severity);
    }

    public function test_three_falls_creates_warning_alert(): void
    {
        $this->actingAs($this->clinicalUser)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'fall_history',
                'completed_at'    => now()->toIso8601String(),
                'responses'       => ['falls_12_months' => 3],
            ])
            ->assertCreated();

        $this->assertEquals(1, Alert::where('alert_type', 'assessment_fall_history_threshold')->count());
    }

    public function test_one_fall_does_not_create_alert(): void
    {
        $this->actingAs($this->clinicalUser)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'fall_history',
                'completed_at'    => now()->toIso8601String(),
                'responses'       => ['falls_12_months' => 1],
            ])
            ->assertCreated();

        $this->assertEquals(0, Alert::where('alert_type', 'assessment_fall_history_threshold')->count());
    }

    public function test_zero_falls_does_not_create_alert(): void
    {
        $this->actingAs($this->clinicalUser)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'fall_history',
                'completed_at'    => now()->toIso8601String(),
                'responses'       => ['falls_12_months' => 0],
            ])
            ->assertCreated();

        $this->assertEquals(0, Alert::where('alert_type', 'assessment_fall_history_threshold')->count());
    }

    public function test_fall_alert_message_contains_fall_count(): void
    {
        $this->actingAs($this->clinicalUser)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'fall_history',
                'completed_at'    => now()->toIso8601String(),
                'responses'       => ['falls_12_months' => 4],
            ])
            ->assertCreated();

        $alert = Alert::where('alert_type', 'assessment_fall_history_threshold')->first();
        $this->assertStringContainsString('4', $alert->message);
    }

    public function test_fall_history_type_label(): void
    {
        $assessment = new Assessment(['assessment_type' => 'fall_history']);
        $this->assertEquals('Fall History Screen', $assessment->typeLabel());
    }
}

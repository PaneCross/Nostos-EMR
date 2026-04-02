<?php

// ─── WoundServiceTest ─────────────────────────────────────────────────────────
// Unit tests for WoundService (W5-1).
//
// Coverage:
//   - open(): creates wound record with correct fields
//   - open(): Stage 3+ pressure injury triggers critical alert (CMS QAPI)
//   - open(): Stage 2 (non-critical) does NOT trigger alert
//   - addAssessment(): creates assessment linked to wound
//   - addAssessment(): status_change='healed' closes wound record
//   - addAssessment(): status_change='deteriorated' creates warning alert
//   - getOpenWounds(): returns only open wounds for participant
//   - getActiveWoundsByTenant(): returns tenant-scoped open wounds
// ─────────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Models\Alert;
use App\Models\Participant;
use App\Models\Site;
use App\Models\User;
use App\Models\WoundRecord;
use App\Services\WoundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WoundServiceTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeParticipant(): Participant
    {
        $user = User::factory()->create(['department' => 'primary_care']);
        $site = Site::factory()->create(['tenant_id' => $user->tenant_id]);
        return Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => $site->id,
        ]);
    }

    private function makeNurse(Participant $participant): User
    {
        return User::factory()->create([
            'department' => 'primary_care',
            'tenant_id'  => $participant->tenant_id,
        ]);
    }

    // ── open() ────────────────────────────────────────────────────────────────

    /** @test */
    public function test_open_creates_wound_record(): void
    {
        $participant = $this->makeParticipant();
        $nurse       = $this->makeNurse($participant);

        $service = app(WoundService::class);
        $wound   = $service->open($participant, [
            'wound_type'            => 'pressure_injury',
            'location'              => 'Sacrum',
            'pressure_injury_stage' => 'stage_2',
            'first_identified_date' => now()->subWeek()->toDateString(),
            'goal'                  => 'healing',
            'documented_by_user_id' => $nurse->id,
        ]);

        $this->assertInstanceOf(WoundRecord::class, $wound);
        $this->assertEquals($participant->id, $wound->participant_id);
        $this->assertEquals($participant->tenant_id, $wound->tenant_id);
        $this->assertEquals('open', $wound->status);
        $this->assertEquals('stage_2', $wound->pressure_injury_stage);
    }

    /** @test */
    public function test_open_with_critical_stage_creates_critical_alert(): void
    {
        $participant = $this->makeParticipant();
        $nurse       = $this->makeNurse($participant);

        $service = app(WoundService::class);
        $service->open($participant, [
            'wound_type'            => 'pressure_injury',
            'location'              => 'Coccyx',
            'pressure_injury_stage' => 'stage_3',   // critical
            'first_identified_date' => now()->toDateString(),
            'goal'                  => 'healing',
            'documented_by_user_id' => $nurse->id,
        ]);

        // Critical alert should exist for primary_care + qa_compliance
        $this->assertDatabaseHas('emr_alerts', [
            'tenant_id'     => $participant->tenant_id,
            'participant_id'=> $participant->id,
            'alert_type'    => 'wound_critical_stage',
            'severity'      => 'critical',
        ]);
    }

    /** @test */
    public function test_open_with_stage_4_creates_critical_alert(): void
    {
        $participant = $this->makeParticipant();
        $nurse       = $this->makeNurse($participant);

        $service = app(WoundService::class);
        $service->open($participant, [
            'wound_type'            => 'pressure_injury',
            'location'              => 'Heel',
            'pressure_injury_stage' => 'stage_4',   // critical
            'first_identified_date' => now()->toDateString(),
            'goal'                  => 'palliative',
            'documented_by_user_id' => $nurse->id,
        ]);

        $this->assertDatabaseHas('emr_alerts', [
            'alert_type' => 'wound_critical_stage',
            'severity'   => 'critical',
        ]);
    }

    /** @test */
    public function test_open_with_stage_2_does_not_create_alert(): void
    {
        $participant = $this->makeParticipant();
        $nurse       = $this->makeNurse($participant);

        $service = app(WoundService::class);
        $service->open($participant, [
            'wound_type'            => 'pressure_injury',
            'location'              => 'Elbow',
            'pressure_injury_stage' => 'stage_2',   // non-critical
            'first_identified_date' => now()->toDateString(),
            'goal'                  => 'healing',
            'documented_by_user_id' => $nurse->id,
        ]);

        $this->assertDatabaseMissing('emr_alerts', [
            'alert_type' => 'wound_critical_stage',
        ]);
    }

    /** @test */
    public function test_open_non_pressure_injury_does_not_create_alert(): void
    {
        $participant = $this->makeParticipant();
        $nurse       = $this->makeNurse($participant);

        $service = app(WoundService::class);
        $service->open($participant, [
            'wound_type'            => 'diabetic_foot_ulcer',
            'location'              => 'Right foot',
            'first_identified_date' => now()->toDateString(),
            'goal'                  => 'healing',
            'documented_by_user_id' => $nurse->id,
        ]);

        $this->assertDatabaseMissing('emr_alerts', [
            'alert_type' => 'wound_critical_stage',
        ]);
    }

    // ── addAssessment() ───────────────────────────────────────────────────────

    /** @test */
    public function test_add_assessment_creates_assessment_record(): void
    {
        $participant = $this->makeParticipant();
        $nurse       = $this->makeNurse($participant);

        $wound = WoundRecord::factory()->open()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $participant->tenant_id,
            'site_id'               => $participant->site_id,
            'documented_by_user_id' => $nurse->id,
        ]);

        $service    = app(WoundService::class);
        $assessment = $service->addAssessment($wound, [
            'assessed_by_user_id' => $nurse->id,
            'length_cm'           => 2.5,
            'status_change'       => 'improved',
        ]);

        $this->assertDatabaseHas('emr_wound_assessments', [
            'wound_record_id'      => $wound->id,
            'assessed_by_user_id'  => $nurse->id,
            'status_change'        => 'improved',
        ]);
    }

    /** @test */
    public function test_healed_assessment_closes_wound_record(): void
    {
        $participant = $this->makeParticipant();
        $nurse       = $this->makeNurse($participant);

        $wound = WoundRecord::factory()->open()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $participant->tenant_id,
            'site_id'               => $participant->site_id,
            'documented_by_user_id' => $nurse->id,
        ]);

        $service = app(WoundService::class);
        $service->addAssessment($wound, [
            'assessed_by_user_id' => $nurse->id,
            'status_change'       => 'healed',
        ]);

        $this->assertEquals('healed', $wound->fresh()->status);
        $this->assertNotNull($wound->fresh()->healed_date);
    }

    /** @test */
    public function test_deteriorated_assessment_creates_warning_alert(): void
    {
        $participant = $this->makeParticipant();
        $nurse       = $this->makeNurse($participant);

        $wound = WoundRecord::factory()->open()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $participant->tenant_id,
            'site_id'               => $participant->site_id,
            'documented_by_user_id' => $nurse->id,
        ]);

        $service = app(WoundService::class);
        $service->addAssessment($wound, [
            'assessed_by_user_id' => $nurse->id,
            'status_change'       => 'deteriorated',
        ]);

        $this->assertDatabaseHas('emr_alerts', [
            'tenant_id'  => $participant->tenant_id,
            'alert_type' => 'wound_deteriorated',
            'severity'   => 'warning',
        ]);
    }

    // ── getOpenWounds() ───────────────────────────────────────────────────────

    /** @test */
    public function test_get_open_wounds_returns_only_non_healed_wounds(): void
    {
        $participant = $this->makeParticipant();
        $nurse       = $this->makeNurse($participant);

        $attrs = [
            'participant_id'        => $participant->id,
            'tenant_id'             => $participant->tenant_id,
            'site_id'               => $participant->site_id,
            'documented_by_user_id' => $nurse->id,
        ];

        WoundRecord::factory()->open()->count(2)->create($attrs);
        WoundRecord::factory()->healed()->create($attrs);

        $service    = app(WoundService::class);
        $openWounds = $service->getOpenWounds($participant->id);

        $this->assertCount(2, $openWounds);
        $this->assertFalse($openWounds->contains('status', 'healed'));
    }

    // ── getActiveWoundsByTenant() ─────────────────────────────────────────────

    /** @test */
    public function test_get_active_wounds_by_tenant_returns_only_own_tenant(): void
    {
        $participant1 = $this->makeParticipant();
        $nurse1       = $this->makeNurse($participant1);

        $participant2 = $this->makeParticipant();  // Different tenant
        $nurse2       = $this->makeNurse($participant2);

        WoundRecord::factory()->open()->create([
            'participant_id'        => $participant1->id,
            'tenant_id'             => $participant1->tenant_id,
            'site_id'               => $participant1->site_id,
            'documented_by_user_id' => $nurse1->id,
        ]);

        WoundRecord::factory()->open()->create([
            'participant_id'        => $participant2->id,
            'tenant_id'             => $participant2->tenant_id,
            'site_id'               => $participant2->site_id,
            'documented_by_user_id' => $nurse2->id,
        ]);

        $service = app(WoundService::class);
        $wounds  = $service->getActiveWoundsByTenant($participant1->tenant_id);

        $this->assertCount(1, $wounds);
        $this->assertEquals($participant1->tenant_id, $wounds->first()->tenant_id);
    }
}

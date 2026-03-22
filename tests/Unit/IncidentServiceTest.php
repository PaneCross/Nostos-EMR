<?php

// ─── IncidentServiceTest ────────────────────────────────────────────────────────
// Unit tests for IncidentService business logic.
//
// Coverage:
//   - createIncident(): auto-sets rca_required for all 6 CMS-mandated types
//   - createIncident(): rca_required=false for non-mandated types
//   - createIncident(): status always starts as 'open'
//   - createIncident(): creates AuditLog entry
//   - submitRca(): sets rca_completed=true, rca_text, status='under_review'
//   - submitRca(): throws LogicException on closed incident
//   - closeIncident(): succeeds when rca_required=false
//   - closeIncident(): succeeds when rca_required=true AND rca_completed=true
//   - closeIncident(): throws LogicException when rca_required=true, rca_completed=false
//   - closeIncident(): throws LogicException if incident already closed
// ──────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Participant;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class IncidentServiceTest extends TestCase
{
    use RefreshDatabase;

    private IncidentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IncidentService();
    }

    private function makeUser(string $dept = 'qa_compliance'): User
    {
        return User::factory()->create(['department' => $dept]);
    }

    private function makeParticipant(User $user): Participant
    {
        return Participant::factory()->create(['tenant_id' => $user->tenant_id]);
    }

    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'incident_type'      => 'behavioral',
            'occurred_at'        => now()->subHour()->toDateTimeString(),
            'description'        => 'Participant exhibited agitated behavior in the day room.',
            'injuries_sustained' => false,
        ], $overrides);
    }

    // ── RCA auto-assignment (CMS 42 CFR 460.136) ──────────────────────────────

    public function test_rca_required_auto_set_for_fall(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);
        $incident    = $this->service->createIncident($participant, $this->baseData(['incident_type' => 'fall']), $user);

        $this->assertTrue($incident->rca_required);
    }

    public function test_rca_required_auto_set_for_medication_error(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);
        $incident    = $this->service->createIncident($participant, $this->baseData(['incident_type' => 'medication_error']), $user);

        $this->assertTrue($incident->rca_required);
    }

    public function test_rca_required_auto_set_for_elopement(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);
        $incident    = $this->service->createIncident($participant, $this->baseData(['incident_type' => 'elopement']), $user);

        $this->assertTrue($incident->rca_required);
    }

    public function test_rca_required_auto_set_for_hospitalization(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);
        $incident    = $this->service->createIncident($participant, $this->baseData(['incident_type' => 'hospitalization']), $user);

        $this->assertTrue($incident->rca_required);
    }

    public function test_rca_required_auto_set_for_er_visit(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);
        $incident    = $this->service->createIncident($participant, $this->baseData(['incident_type' => 'er_visit']), $user);

        $this->assertTrue($incident->rca_required);
    }

    public function test_rca_required_auto_set_for_abuse_neglect(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);
        $incident    = $this->service->createIncident($participant, $this->baseData(['incident_type' => 'abuse_neglect']), $user);

        $this->assertTrue($incident->rca_required);
    }

    public function test_rca_not_required_for_behavioral(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);
        $incident    = $this->service->createIncident($participant, $this->baseData(['incident_type' => 'behavioral']), $user);

        $this->assertFalse($incident->rca_required);
    }

    public function test_rca_not_required_for_injury_only_type(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);
        $incident    = $this->service->createIncident($participant, $this->baseData(['incident_type' => 'injury']), $user);

        $this->assertFalse($incident->rca_required);
    }

    // ── createIncident() base behavior ────────────────────────────────────────

    public function test_create_incident_status_always_open(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);
        $incident    = $this->service->createIncident($participant, $this->baseData(), $user);

        $this->assertEquals('open', $incident->status);
    }

    public function test_create_incident_records_audit_log(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        $this->service->createIncident($participant, $this->baseData(), $user);

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'        => 'qa.incident.created',
            'tenant_id'     => $user->tenant_id,
            'user_id'       => $user->id,
            'resource_type' => 'incident',
        ]);
    }

    // ── submitRca() ───────────────────────────────────────────────────────────

    public function test_submit_rca_marks_rca_completed(): void
    {
        $user     = $this->makeUser();
        $incident = Incident::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $this->makeParticipant($user)->id,
            'rca_required'   => true,
            'rca_completed'  => false,
        ]);

        $this->service->submitRca($incident, str_repeat('Root cause analysis text. ', 5), $user);

        $fresh = $incident->fresh();
        $this->assertTrue($fresh->rca_completed);
        $this->assertEquals($user->id, $fresh->rca_completed_by_user_id);
        $this->assertEquals('under_review', $fresh->status);
    }

    public function test_submit_rca_persists_rca_text(): void
    {
        $user     = $this->makeUser();
        $incident = Incident::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $this->makeParticipant($user)->id,
            'rca_required'   => true,
        ]);
        $rcaText = str_repeat('Detailed root cause analysis findings. ', 5);

        $this->service->submitRca($incident, $rcaText, $user);

        $this->assertEquals($rcaText, $incident->fresh()->rca_text);
    }

    public function test_submit_rca_throws_if_incident_closed(): void
    {
        $user     = $this->makeUser();
        $incident = Incident::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $this->makeParticipant($user)->id,
            'status'         => 'closed',
        ]);

        $this->expectException(LogicException::class);
        $this->service->submitRca($incident, str_repeat('RCA text. ', 10), $user);
    }

    // ── closeIncident() ───────────────────────────────────────────────────────

    public function test_close_succeeds_when_no_rca_required(): void
    {
        $user     = $this->makeUser();
        $incident = Incident::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $this->makeParticipant($user)->id,
            'rca_required'   => false,
        ]);

        $this->service->closeIncident($incident, $user);

        $this->assertEquals('closed', $incident->fresh()->status);
    }

    public function test_close_succeeds_when_rca_required_and_completed(): void
    {
        $user     = $this->makeUser();
        $incident = Incident::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $this->makeParticipant($user)->id,
            'rca_required'   => true,
            'rca_completed'  => true,
        ]);

        $this->service->closeIncident($incident, $user);

        $this->assertEquals('closed', $incident->fresh()->status);
    }

    public function test_close_throws_when_rca_required_but_not_completed(): void
    {
        $user     = $this->makeUser();
        $incident = Incident::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $this->makeParticipant($user)->id,
            'rca_required'   => true,
            'rca_completed'  => false,
        ]);

        $this->expectException(LogicException::class);
        $this->service->closeIncident($incident, $user);
    }

    public function test_close_throws_if_already_closed(): void
    {
        $user     = $this->makeUser();
        $incident = Incident::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $this->makeParticipant($user)->id,
            'status'         => 'closed',
        ]);

        $this->expectException(LogicException::class);
        $this->service->closeIncident($incident, $user);
    }
}
